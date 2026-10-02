<?php

use App\Modules\Experiments\Application\ExperimentAssignments;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentEligibility;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment required.');
    }
});

it('keeps one assignment under competing PostgreSQL workers', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Artisan::call('migrate', ['--force' => true]);
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert(['id' => $org, 'name' => 'Experiment contention',
        'slug' => 'exp-contention-'.Str::random(12), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspaces')->insert(['id' => $workspace, 'organization_id' => $org,
        'name' => 'Experiment contention', 'slug' => 'exp-contention-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now()]);
    $owner = new TenantContext($org, $workspace, null, 'owner');
    $reviewer = new TenantContext($org, $workspace, null, 'reviewer');
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return true;
        }
    };
    $witness = new class implements ExposureVerifier
    {
        public function witnessed(TenantContext $actor, string $experimentId, string $assignmentId, string $variant, string $reference, DateTimeImmutable $at): bool
        {
            return false;
        }
    };
    $eligibility = new class implements ExperimentEligibility
    {
        public function allows(TenantContext $actor, string $unitKind, string $unitId): bool
        {
            return $unitKind === 'contact' && $unitId === 'subject-1';
        }
    };
    $service = new ExperimentAssignments(new ExperimentAllocator(str_repeat('p', 32)), $access, $witness, $eligibility);
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'pg-layer-'.Str::random(8), 'contact',
        ['control' => 5000, 'variant' => 5000], 'control', null);
    $service->create($owner, $plan);
    $service->activate($reviewer, $plan->id);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->toBeArray();
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        $exit = 1;
        try {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 10);
            if (fread($pair[1], 1) !== 'S') {
                throw new RuntimeException('Missing start signal.');
            }
            $assignment = $service->assign($owner, $plan->id, 'subject-1');
            if (fwrite($pair[1], $assignment['id']) === 36) {
                $exit = 0;
            }
        } catch (Throwable) {
            $exit = 1;
        } finally {
            fclose($pair[1]);
            exit($exit);
        }
    }
    fclose($pair[1]);
    try {
        DB::beginTransaction();
        $parent = $service->assign($owner, $plan->id, 'subject-1');
        fwrite($pair[0], 'S');
        usleep(150000);
        DB::commit();
        stream_set_timeout($pair[0], 10);
        expect(fread($pair[0], 36))->toBe($parent['id']);
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('experiment_assignments')->where('experiment_id', $plan->id)->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pair[0]);
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
        DB::table('experiment_assignments')->where('experiment_id', $plan->id)->delete();
        DB::table('experiments')->where('id', $plan->id)->delete();
        DB::table('workspaces')->where('id', $workspace)->delete();
        DB::table('organizations')->where('id', $org)->delete();
    }
});
