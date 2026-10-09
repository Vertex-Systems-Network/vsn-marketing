<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineRollbackReview;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRollbackReviewEvent;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyRollbackOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
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

function offlineRollbackConcurrencyRecorder(): DatabaseBoundedAutonomyOfflineRollbackReviewEvent
{
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };

    return new DatabaseBoundedAutonomyOfflineRollbackReviewEvent(
        new BoundedAutonomyOfflineRollbackReview(new DenyingBoundedAutonomyRollbackOutcomeSource),
        $access,
    );
}

it('serializes concurrent review of one unknown provider outcome without duplicate evidence or false rollback', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Artisan::call('migrate', ['--force' => true]);

    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Rollback contention org',
        'slug' => 'rollback-pg-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org,
        'name' => 'Rollback contention workspace', 'slug' => 'rollback-pg-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $scope = new TenantContext($org, $workspace, null, 'operator');
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $digest = str_repeat('a', 64);
    $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pipe)->toBeArray();

    // Never inherit a libpq socket across a fork, including the parent PDO.
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        $exitCode = 1;
        try {
            fclose($pipe[0]);
            stream_set_timeout($pipe[1], 10);
            if (fread($pipe[1], 1) !== 'S') {
                throw new RuntimeException('Worker never started.');
            }
            $r = offlineRollbackConcurrencyRecorder()->record($scope, 'run-1', $digest, $at);
            $exitCode = fwrite($pipe[1], $r['replayed'] ? '1' : '0') === 1 ? 0 : 1;
        } catch (Throwable) {
            $exitCode = 1;
        } finally {
            fclose($pipe[1]);
            exit($exitCode);
        }
    }

    fclose($pipe[1]);
    try {
        DB::beginTransaction();
        $one = offlineRollbackConcurrencyRecorder()->record($scope, 'run-1', $digest, $at);
        expect($one['replayed'])->toBeFalse()
            ->and($one['reason_code'])->toBe('provider_outcome_unknown')
            ->and($one['rollback_performed'])->toBeFalse();
        fwrite($pipe[0], 'S');
        usleep(150000);
        DB::commit();

        stream_set_timeout($pipe[0], 10);
        expect(fread($pipe[0], 1))->toBe('1');
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('ai_autonomy_offline_rollback_events')
                ->where('workspace_id', $workspace)->count())->toBe(1);
        $event = DB::table('ai_autonomy_offline_rollback_events')
            ->where('workspace_id', $workspace)->first();
        expect((int) $event->sequence)->toBe(1)
            ->and($event->status)->toBe('held_offline')
            ->and($event->external_outcome_verified)->toBeFalse();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pipe[0]);
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
        DB::table('ai_autonomy_offline_rollback_events')->where('workspace_id', $workspace)->delete();
        DB::table('workspaces')->where('id', $workspace)->delete();
        DB::table('organizations')->where('id', $org)->delete();
    }
});
