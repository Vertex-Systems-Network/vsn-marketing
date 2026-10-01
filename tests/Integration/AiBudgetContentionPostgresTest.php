<?php

use App\Modules\AI\Infrastructure\DatabaseAiBudgetLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment is required.');
    }
});

it('serializes competing workers under the same workspace budget row', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Artisan::call('migrate', ['--force' => true]);
    $organization = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    $period = gmdate('Y-m-d');
    DB::table('organizations')->insert(['id' => $organization, 'name' => 'AI contention fixture',
        'slug' => 'ai-contention-'.Str::random(12), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspaces')->insert(['id' => $workspace, 'organization_id' => $organization,
        'name' => 'AI contention fixture', 'slug' => 'ai-contention-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('ai_workspace_budgets')->insert(['workspace_id' => $workspace, 'period_utc' => $period,
        'limit_minor' => 50, 'reserved_minor' => 0, 'spent_minor' => 0,
        'created_at' => now(), 'updated_at' => now()]);

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->toBeArray();
    // libpq sockets must be closed BEFORE fork. Closing an inherited PDO in the
    // child can terminate the server session still in use by the parent.
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        $exitStatus = 1;
        try {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 10);
            if (fread($pair[1], 1) !== 'S') {
                throw new RuntimeException('Contention worker did not receive its start signal.');
            }
            $reserved = (new DatabaseAiBudgetLedger)->reserve($workspace, 'child', 40);
            if (fwrite($pair[1], $reserved ? '1' : '0') === 1) {
                $exitStatus = 0;
            }
        } catch (Throwable) {
            // Never let a worker exception return to PHPUnit's suite runner.
            $exitStatus = 1;
        } finally {
            fclose($pair[1]);
            exit($exitStatus);
        }
    }

    fclose($pair[1]);
    try {
        DB::beginTransaction();
        expect((new DatabaseAiBudgetLedger)->reserve($workspace, 'parent', 40))->toBeTrue();
        fwrite($pair[0], 'S');
        usleep(150000);
        DB::commit();
        stream_set_timeout($pair[0], 10);
        expect(fread($pair[0], 1))->toBe('0');
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and((int) DB::table('ai_workspace_budgets')->where('workspace_id', $workspace)->value('reserved_minor'))->toBe(40)
            ->and(DB::table('ai_budget_reservations')->where('workspace_id', $workspace)->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pair[0]);
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
        DB::table('ai_budget_reservations')->where('workspace_id', $workspace)->delete();
        DB::table('ai_workspace_budgets')->where('workspace_id', $workspace)->delete();
        DB::table('workspaces')->where('id', $workspace)->delete();
        DB::table('organizations')->where('id', $organization)->delete();
    }
});
