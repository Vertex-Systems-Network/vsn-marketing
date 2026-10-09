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
        $this->markTestSkipped('PostgreSQL integration environment is required for atomic autonomy quota contention.');
    }
});

it('serializes two competing offline claims without exceeding workspace tokens, spend or attempt ceilings', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Artisan::call('migrate', ['--force' => true]);

    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Autonomy quota contention', 'slug' => 'autonomy-quota-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org, 'name' => 'Autonomy quota workspace',
        'slug' => 'autonomy-quota-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_global_stops')->updateOrInsert(['id' => 'global'], [
        'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $workspace, 'period_utc' => $at->format('Y-m-d'),
        'policy_version' => 'v1', 'workspace_stopped' => false,
        'max_actions' => 1, 'max_tokens' => 80, 'max_volume' => 3,
        'max_cost_minor' => 18, 'max_attempts' => 1,
        'policy_expires_at' => $at->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $scope = new TenantContext($org, $workspace, null, 'operator');
    $preview = new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['count'], 1,
    );
    $goal = [
        'workspace_id' => $workspace, 'brand_id' => null, 'policy_version' => 'v1',
        'purpose' => 'campaign_optimization', 'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ];
    $actions = [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]];
    $estimate = ['actions' => 1, 'tokens' => 60, 'volume' => 2, 'cost_minor' => 11, 'attempts' => 1];

    $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pipe)->toBeArray();

    // Never fork an inherited active libpq socket: reconnect each process.
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        $exitCode = 1;
        try {
            fclose($pipe[0]);
            stream_set_timeout($pipe[1], 10);
            if (fread($pipe[1], 1) !== 'S') {
                throw new RuntimeException('No child start signal.');
            }
            $childPreview = $preview->preview($scope, 'offline-child', $goal, $actions, $at);
            $r = (new DatabaseBoundedAutonomyOfflineReservation)->reserve($scope, $childPreview, $estimate, $at);
            $exitCode = fwrite($pipe[1], $r['status'] === 'held_offline' ? '0' : '1') === 1 ? 0 : 1;
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
        $parent = (new DatabaseBoundedAutonomyOfflineReservation)->reserve(
            $scope, $preview->preview($scope, 'offline-parent', $goal, $actions, $at), $estimate, $at,
        );
        expect($parent['status'])->toBe('reserved_offline')
            ->and($parent['execution_authorized'])->toBeFalse();

        fwrite($pipe[0], 'S');
        usleep(150000);
        DB::commit();

        stream_set_timeout($pipe[0], 10);
        expect(fread($pipe[0], 1))->toBe('0');
        pcntl_waitpid($pid, $status);
        $pid = 0;
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(DB::table('ai_autonomy_offline_reservations')
                ->where('workspace_id', $workspace)->count())->toBe(1);

        $q = DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $workspace)->first();
        expect((int) $q->used_actions)->toBe(1)
            ->and((int) $q->used_tokens)->toBe(60)
            ->and((int) $q->reserved_cost_minor)->toBe(11)
            ->and((int) $q->used_attempts)->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pipe[0]);
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
        DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $workspace)->delete();
        DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $workspace)->delete();
        DB::table('ai_autonomy_global_stops')->where('id', 'global')->delete();
        DB::table('workspaces')->where('id', $workspace)->delete();
        DB::table('organizations')->where('id', $org)->delete();
    }
});
