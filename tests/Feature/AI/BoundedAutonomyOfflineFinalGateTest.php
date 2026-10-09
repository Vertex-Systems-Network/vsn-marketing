<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineFinalGate;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function finalGateFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Offline final gate',
        'slug' => 'final-ai-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Final gate workspace',
        'slug' => 'final-ai-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $requester = User::query()->create([
        'name' => 'Final gate requester', 'email' => Str::random(12).'@example.test',
        'password' => Str::random(24),
    ]);
    $approver = User::query()->create([
        'name' => 'Final gate approver', 'email' => Str::random(12).'@example.test',
        'password' => Str::random(24),
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $member = $roles->addMember($approver, $ws);
    $role = $roles->createRole($ws, 'approver', 'Offline approver');
    $roles->assignRole($member, $role);
    $roles->grantPermission($role, PermissionCatalog::AI_APPROVE);

    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $scope = new TenantContext($org, $ws, null, (string) $requester->getKey());
    $preview = (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['count'], 1,
    ))->preview($scope, 'run-final', [
        'workspace_id' => $ws, 'brand_id' => null,
        'policy_version' => 'v1', 'purpose' => 'campaign_optimization',
        'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]], $at);
    $binding = [
        'audience_sha256' => str_repeat('b', 64),
        'content_sha256' => str_repeat('c', 64),
        'destination_sha256' => str_repeat('d', 64),
        'max_cost_minor' => 10, 'max_volume' => 2,
        'not_before_unix' => $at->getTimestamp() - 60,
        'expires_at_unix' => $at->getTimestamp() + 300,
    ];

    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $ws, 'period_utc' => $at->format('Y-m-d'),
        'policy_version' => 'v1', 'workspace_stopped' => false,
        'max_actions' => 2, 'max_tokens' => 100,
        'max_volume' => 8, 'max_cost_minor' => 50, 'max_attempts' => 4,
        'used_actions' => 1, 'used_tokens' => 40, 'used_volume' => 2,
        'reserved_cost_minor' => 10, 'spent_cost_minor' => 0, 'used_attempts' => 1,
        'policy_expires_at' => '2026-10-09 12:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_offline_reservations')->insert([
        'workspace_id' => $ws, 'period_utc' => $at->format('Y-m-d'),
        'run_id' => $preview['run_id'], 'brand_id' => null,
        'actor_id' => $scope->actorId,
        'snapshot_sha256' => $preview['snapshot_sha256'],
        'policy_version' => 'v1', 'actions' => 1, 'tokens' => 40,
        'volume' => 2, 'cost_minor' => 10, 'attempts' => 1,
        'status' => 'offline_reserved', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_offline_approval_decisions')->insert([
        'decision_id' => (string) Str::uuid(),
        'workspace_id' => $ws, 'brand_id' => null, 'run_id' => $preview['run_id'],
        'snapshot_sha256' => $preview['snapshot_sha256'], 'policy_version' => 'v1',
        ...$binding, 'approved_at_unix' => $at->getTimestamp() - 10,
        'approver_id' => (string) $approver->getKey(),
        'outcome' => 'approved', 'created_at' => now(),
    ]);

    return [$scope, $preview, $binding, $at, $role];
}

it('offers operator-only final review with exact current independent approval', function () {
    [$scope, $preview, $binding, $at] = finalGateFixture();
    $r = app(BoundedAutonomyOfflineFinalGate::class)->inspect($scope, $preview, $binding, $at);
    expect($r['status'])->toBe('offline_final_review_ready')
        ->and($r['execution_authorized'])->toBeFalse()
        ->and($r['promotion_authorized'])->toBeFalse()
        ->and($r['external_outcome_verified'])->toBeFalse();
});

it('rechecks global and workspace stops and held reservation at the last boundary', function () {
    [$scope, $preview, $binding, $at] = finalGateFixture();
    $gate = app(BoundedAutonomyOfflineFinalGate::class);

    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
    expect($gate->inspect($scope, $preview, $binding, $at)['reason_code'])->toBe('global_emergency_stop');
    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => false]);

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => true]);
    expect($gate->inspect($scope, $preview, $binding, $at)['reason_code'])
        ->toBe('workspace_emergency_stop_or_unconfigured');
    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => false]);

    DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $scope->workspaceId)
        ->update(['status' => 'held_by_emergency_stop']);
    expect($gate->inspect($scope, $preview, $binding, $at)['reason_code'])
        ->toBe('current_offline_reservation_unavailable');
});

it('rejects approval revocation, role loss and increased spend at final preflight', function () {
    [$scope, $preview, $binding, $at, $role] = finalGateFixture();
    $gate = app(BoundedAutonomyOfflineFinalGate::class);
    expect($gate->inspect($scope, $preview, array_replace($binding, ['max_cost_minor' => 9]), $at)['status'])
        ->toBe('held_offline');

    DB::table('ai_autonomy_offline_approval_decisions')->insert([
        'decision_id' => (string) Str::uuid(),
        'workspace_id' => $scope->workspaceId, 'brand_id' => null,
        'run_id' => $preview['run_id'], 'snapshot_sha256' => $preview['snapshot_sha256'],
        'policy_version' => 'v1', ...$binding,
        'approved_at_unix' => $at->getTimestamp() - 5,
        'approver_id' => DB::table('ai_autonomy_offline_approval_decisions')->value('approver_id'),
        'outcome' => 'revoked', 'created_at' => now(),
    ]);
    expect($gate->inspect($scope, $preview, $binding, $at)['reason_code'])->toBe('approval_revoked');

    DB::table('workspace_role_permissions')->where('workspace_role_id', $role)
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    expect($gate->inspect($scope, $preview, $binding, $at)['reason_code'])
        ->toBe('independent_approval_unavailable');
});
