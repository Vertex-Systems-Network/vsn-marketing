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
        $this->markTestSkipped('Four-worker synthetic offline quota stress requires PostgreSQL.');
    }
});

it('serializes four independent workers under a single offline admission quota and rate ceiling', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Artisan::call('migrate', ['--force' => true]);

    $at = new DateTimeImmutable('2026-10-12T09:00:00+00:00');
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    $period = $at->format('Y-m-d');
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Offline red-team stress organization',
        'slug' => 'redteam-stress-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org,
        'name' => 'Offline quota stress workspace',
        'slug' => 'redteam-stress-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_global_stops')->updateOrInsert(['id' => 'global'], [
        'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $ws, 'period_utc' => $period,
        'policy_version' => 'v1', 'workspace_stopped' => false,
        'max_actions' => 1, 'max_tokens' => 80, 'max_volume' => 3,
        'max_cost_minor' => 18, 'max_attempts' => 1,
        'policy_expires_at' => $at->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_rate_windows')->insert([
        'workspace_id' => $ws, 'period_utc' => $period,
        'policy_version' => 'v1', 'max_attempts_per_minute' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $scope = new TenantContext($org, $ws, null, 'operator');
    $preview = new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['count'], 1,
    );
    $goal = [
        'workspace_id' => $ws, 'brand_id' => null,
        'policy_version' => 'v1', 'purpose' => 'campaign_optimization',
        'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 1800,
    ];
    $actions = [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]];
    $estimate = ['actions' => 1, 'tokens' => 60, 'volume' => 2, 'cost_minor' => 11, 'attempts' => 1];

    $children = [];
    $opened = [];
    // No inherited libpq sockets: child workers create independent
    // connections and transactions after the fork.
    DB::purge();
    try {
        for ($i = 0; $i < 4; $i++) {
            $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            expect($pipe)->toBeArray();
            $pid = pcntl_fork();
            expect($pid)->not->toBe(-1);
            if ($pid === 0) {
                $status = 1;
                try {
                    fclose($pipe[0]);
                    stream_set_timeout($pipe[1], 15);
                    if (fread($pipe[1], 1) !== 'S') {
                        throw new RuntimeException('Missing synthetic worker start signal.');
                    }
                    $plan = $preview->preview($scope, 'redteam-worker-'.$i, $goal, $actions, $at);
                    $result = (new DatabaseBoundedAutonomyOfflineReservation)->reserve(
                        $scope, $plan, $estimate, $at,
                    );
                    $safe = ($result['execution_authorized'] ?? null) === false
                        && ($result['promotion_authorized'] ?? null) === false;
                    $marker = $safe && $result['status'] === 'reserved_offline' ? 'R'
                        : ($safe && $result['status'] === 'held_offline' ? 'H' : 'F');
                    $status = fwrite($pipe[1], $marker) === 1 && $marker !== 'F' ? 0 : 1;
                } catch (Throwable) {
                    $status = 1;
                } finally {
                    fclose($pipe[1]);
                    exit($status);
                }
            }
            fclose($pipe[1]);
            $children[] = $pid;
            $opened[] = $pipe[0];
        }

        // All independent workers compete for exactly one allowable claim.
        foreach ($opened as $pipe) {
            expect(fwrite($pipe, 'S'))->toBe(1);
        }
        $markers = [];
        foreach ($opened as $pipe) {
            stream_set_timeout($pipe, 15);
            $markers[] = fread($pipe, 1);
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $children = [];
        expect(count(array_filter($markers, static fn ($code): bool => $code === 'R')))->toBe(1)
            ->and(count(array_filter($markers, static fn ($code): bool => $code === 'H')))->toBe(3)
            ->and(DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $ws)->count())->toBe(1);

        $quota = DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $ws)->first();
        $rate = DB::table('ai_autonomy_workspace_rate_windows')->where('workspace_id', $ws)->first();
        expect((int) $quota->used_actions)->toBe(1)
            ->and((int) $quota->used_tokens)->toBe(60)
            ->and((int) $quota->used_volume)->toBe(2)
            ->and((int) $quota->used_attempts)->toBe(1)
            ->and((int) $quota->reserved_cost_minor)->toBe(11)
            ->and((int) $quota->spent_cost_minor)->toBe(0)
            ->and((int) $rate->window_used_attempts)->toBe(1);
    } finally {
        foreach ($opened as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        foreach ($children as $pid) {
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
