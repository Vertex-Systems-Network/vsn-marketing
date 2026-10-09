<?php

use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyStopReconciliation;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function autonomyStopFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Offline stop fixture',
        'slug' => 'stop-'.Str::random(12), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Offline stop workspace',
        'slug' => 'stop-'.Str::random(12), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $ws, 'period_utc' => '2026-10-09', 'policy_version' => 'v1',
        'workspace_stopped' => false, 'max_actions' => 2, 'max_tokens' => 100,
        'max_volume' => 8, 'max_cost_minor' => 50, 'max_attempts' => 4,
        'used_actions' => 1, 'used_tokens' => 40, 'used_volume' => 2,
        'reserved_cost_minor' => 20, 'spent_cost_minor' => 0, 'used_attempts' => 1,
        'policy_expires_at' => '2026-10-09 12:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $digest = str_repeat('a', 64);
    DB::table('ai_autonomy_offline_reservations')->insert([
        'workspace_id' => $ws, 'period_utc' => '2026-10-09',
        'run_id' => 'run-1', 'brand_id' => null, 'actor_id' => 'operator',
        'snapshot_sha256' => $digest, 'policy_version' => 'v1',
        'actions' => 1, 'tokens' => 40, 'volume' => 2, 'cost_minor' => 20,
        'attempts' => 1, 'status' => 'offline_reserved',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [new TenantContext($org, $ws, null, 'operator'), $digest];
}

it('does not infer a stop or fake external outcomes when authority is active', function () {
    [$scope, $sha] = autonomyStopFixture();
    $s = new DatabaseBoundedAutonomyStopReconciliation;
    expect($s->reconcile($scope, 'run-1', $sha)['reason_code'])->toBe('stop_not_active')
        ->and($s->reconcile($scope, 'run-missing', $sha)['reason_code'])->toBe('reservation_missing');
    expect(DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $scope->workspaceId)
        ->value('status'))->toBe('offline_reserved');
});

it('reconciles global or workspace stop exactly once and never releases reserved cost', function () {
    [$scope, $sha] = autonomyStopFixture();
    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
    $s = new DatabaseBoundedAutonomyStopReconciliation;
    $first = $s->reconcile($scope, 'run-1', $sha);
    expect($first['reason_code'])->toBe('stop_recorded_offline')
        ->and($first['execution_authorized'])->toBeFalse()
        ->and($first['resource_counters_preserved'])->toBeTrue();
    expect($s->reconcile($scope, 'run-1', $sha)['reason_code'])->toBe('already_held');
    $q = DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)->first();
    expect((int) $q->reserved_cost_minor)->toBe(20)
        ->and((int) $q->used_tokens)->toBe(40)
        ->and(DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $scope->workspaceId)
            ->value('status'))->toBe('held_by_emergency_stop');
});

it('holds unknown outcomes and rejects different actors or evidence', function () {
    [$scope, $sha] = autonomyStopFixture();
    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => true]);
    $s = new DatabaseBoundedAutonomyStopReconciliation;
    expect(fn () => $s->reconcile(
        new TenantContext($scope->organizationId, $scope->workspaceId, null, 'other'), 'run-1', $sha,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $s->reconcile($scope, 'run-1', str_repeat('b', 64)))
        ->toThrow(InvalidArgumentException::class);

    DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $scope->workspaceId)
        ->update(['status' => 'external_unconfirmed']);
    $result = $s->reconcile($scope, 'run-1', $sha);
    expect($result['reason_code'])->toBe('external_outcome_unverified')
        ->and($result['external_outcome_verified'])->toBeFalse()
        ->and((int) DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
            ->value('reserved_cost_minor'))->toBe(20);
});

it('treats absent global stop configuration as a fail-closed hold', function () {
    [$scope, $sha] = autonomyStopFixture();
    DB::table('ai_autonomy_global_stops')->where('id', 'global')->delete();
    $r = (new DatabaseBoundedAutonomyStopReconciliation)->reconcile($scope, 'run-1', $sha);
    expect($r['reason_code'])->toBe('stop_recorded_offline')
        ->and($r['execution_authorized'])->toBeFalse();
});
