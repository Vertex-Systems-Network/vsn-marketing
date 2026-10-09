<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineApprovalReview;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyApprovalSource;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function aiAuthorityFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Offline approver authority',
        'slug' => 'offline-approve-'.Str::random(9), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Approval workspace',
        'slug' => 'offline-approve-'.Str::random(9), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $requester = User::query()->create([
        'name' => 'Requesting agent operator', 'email' => Str::random(12).'@example.org',
        'password' => Str::random(25),
    ]);
    $approver = User::query()->create([
        'name' => 'Independent approver', 'email' => Str::random(12).'@example.org',
        'password' => Str::random(25),
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($approver, $ws);
    $role = $roles->createRole($ws, 'approver', 'Independent AI approver');
    $roles->assignRole($membership, $role);

    return [
        'scope' => new TenantContext($org, $ws, null, (string) $requester->getKey()),
        'requester' => $requester,
        'approver' => $approver,
        'role' => $role,
    ];
}

function aiAuthorityAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
}

function aiAuthorityPreview(TenantContext $actor): array
{
    $at = aiAuthorityAt();

    return (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['count'], 1,
    ))->preview($actor, 'approval-run-1', [
        'workspace_id' => $actor->workspaceId, 'brand_id' => null,
        'policy_version' => 'v1', 'purpose' => 'campaign_optimization',
        'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]], $at);
}

function aiAuthorityBinding(): array
{
    return [
        'audience_sha256' => str_repeat('b', 64),
        'content_sha256' => str_repeat('c', 64),
        'destination_sha256' => str_repeat('d', 64),
        'max_cost_minor' => 12,
        'max_volume' => 4,
        'not_before_unix' => aiAuthorityAt()->getTimestamp() - 60,
        'expires_at_unix' => aiAuthorityAt()->getTimestamp() + 3600,
    ];
}

function aiAuthorityDecision(TenantContext $scope, User $approver, array $preview, array $patch = []): string
{
    $id = (string) Str::uuid();
    DB::table('ai_autonomy_offline_approval_decisions')->insert(array_replace([
        'decision_id' => $id,
        'workspace_id' => $scope->workspaceId,
        'brand_id' => $scope->brandId,
        'run_id' => $preview['run_id'],
        'snapshot_sha256' => $preview['snapshot_sha256'],
        'policy_version' => $preview['policy_version'],
        ...aiAuthorityBinding(),
        'approved_at_unix' => aiAuthorityAt()->getTimestamp() - 30,
        'approver_id' => (string) $approver->getKey(),
        'outcome' => 'approved',
        'created_at' => now(),
    ], $patch));

    return $id;
}

it('requires current independent AI approval permission and never enables production execution', function () {
    $f = aiAuthorityFixture();
    $scope = $f['scope'];
    $preview = aiAuthorityPreview($scope);
    $service = new BoundedAutonomyOfflineApprovalReview(
        new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class)),
    );

    $noEvidence = $service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt());
    expect($noEvidence['status'])->toBe('approval_held_offline');
    aiAuthorityDecision($scope, $f['approver'], $preview);
    $noRole = $service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt());
    expect($noRole['reason_code'])->toBe('independent_approval_unavailable');

    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    $valid = $service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt());
    expect($valid['status'])->toBe('approval_matched_offline')
        ->and($valid['execution_authorized'])->toBeFalse()
        ->and($valid['promotion_authorized'])->toBeFalse();

    DB::table('workspace_role_permissions')->where('workspace_role_id', $f['role'])
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    $revokedAuthority = $service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt());
    expect($revokedAuthority['reason_code'])->toBe('independent_approval_unavailable');
});

it('latest rejection or revocation supersedes approved rows; material changes cannot reuse approval', function () {
    $f = aiAuthorityFixture();
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    $scope = $f['scope'];
    $preview = aiAuthorityPreview($scope);
    $service = new BoundedAutonomyOfflineApprovalReview(
        new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class)),
    );
    aiAuthorityDecision($scope, $f['approver'], $preview);
    expect($service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt())['status'])
        ->toBe('approval_matched_offline');
    aiAuthorityDecision($scope, $f['approver'], $preview, ['outcome' => 'revoked']);
    expect($service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt())['reason_code'])
        ->toBe('approval_revoked');
    $changed = array_replace(aiAuthorityBinding(), ['max_cost_minor' => 99]);
    expect($service->inspect($scope, $preview, $changed, aiAuthorityAt())['status'])
        ->toBe('approval_held_offline');

    aiAuthorityDecision($scope, $f['approver'], $preview, [
        'outcome' => 'approved',
        'audience_sha256' => str_repeat('f', 64),
    ]);
    expect($service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt())['reason_code'])
        ->toBe('approval_binding_changed');
});

it('cross-workspace and self-approvals are held even with a recorded decision', function () {
    $f = aiAuthorityFixture();
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    $scope = $f['scope'];
    $preview = aiAuthorityPreview($scope);
    $source = new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class));
    $service = new BoundedAutonomyOfflineApprovalReview($source);
    aiAuthorityDecision($scope, $f['approver'], $preview);
    $foreign = new TenantContext($scope->organizationId, (string) Str::uuid(), null, $scope->actorId);
    expect($source->latest($foreign, $preview['run_id'], aiAuthorityAt()))->toBeNull();

    aiAuthorityDecision($scope, $f['requester'], $preview);
    $result = $service->inspect($scope, $preview, aiAuthorityBinding(), aiAuthorityAt());
    expect($result['status'])->toBe('approval_held_offline')
        ->and($result['execution_authorized'])->toBeFalse();
});
