<?php

use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineReservation;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration required for stop/admission race.');
    }
});

it('serializes global stop ahead of a competing offline reservation without consuming quota', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Artisan::call('migrate', ['--force' => true]);
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Autonomy stop race',
        'slug' => 'autonomy-stop-race-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Autonomy stop race workspace',
        'slug' => 'autonomy-stop-race-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_global_stops')->updateOrInsert(['id' => 'global'], [
        'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $ws, 'period_utc' => '2026-10-09',
        'policy_version' => 'v1', 'workspace_stopped' => false,
        'max_actions' => 2, 'max_tokens' => 100, 'max_volume' => 10,
        'max_cost_minor' => 100, 'max_attempts' => 2,
        'policy_expires_at' => '2026-10-09 10:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_rate_windows')->insert([
        'workspace_id' => $ws, 'period_utc' => '2026-10-09',
        'policy_version' => 'v1', 'max_attempts_per_minute' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $scope = new TenantContext($org, $ws, null, 'operator');
    $preview = (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['count'], 1,
    ))->preview($scope, 'stop-race', [
        'workspace_id' => $ws, 'brand_id' => null, 'policy_version' => 'v1',
        'purpose' => 'campaign_optimization', 'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]], $at);
    $estimate = ['actions' => 1, 'tokens' => 10, 'volume' => 1, 'cost_minor' => 5, 'attempts' => 1];

    $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pipe)->toBeArray();
    // Never carry inherited PostgreSQL PDO sockets across a fork.
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        $exitCode = 1;
        try {
            fclose($pipe[0]);
            stream_set_timeout($pipe[1], 10);
            if (fread($pipe[1], 1) !== 'S') {
                throw new RuntimeException('Missing stop-race start signal.');
            }
            $result = (new DatabaseBoundedAutonomyOfflineReservation)->reserve($scope, $preview, $estimate, $at);
            $held = $result['status'] === 'held_offline'
                && $result['reason_code'] === 'global_emergency_stop'
                && $result['execution_authorized'] === false;
            $exitCode = fwrite($pipe[1], $held ? '0' : '1') === 1 ? 0 : 1;
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
        DB::table('ai_autonomy_global_stops')->where('id', 'global')->lockForUpdate()->first();
        DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
        fwrite($pipe[0], 'S');
        usleep(150000);
        DB::commit();

        stream_set_timeout($pipe[0], 10);
        expect(fread($pipe[0], 1))->toBe('0');
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $ws)->count())->toBe(0)
            ->and((int) DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $ws)
                ->value('used_attempts'))->toBe(0)
            ->and((int) DB::table('ai_autonomy_workspace_rate_windows')->where('workspace_id', $ws)
                ->value('window_used_attempts'))->toBe(0);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pipe[0]);
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
        DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $ws)->delete();
        DB::table('ai_autonomy_workspace_rate_windows')->where('workspace_id', $ws)->delete();
        DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $ws)->delete();
        DB::table('ai_autonomy_global_stops')->where('id', 'global')->delete();
        DB::table('workspaces')->where('id', $ws)->delete();
        DB::table('organizations')->where('id', $org)->delete();
    }
});
