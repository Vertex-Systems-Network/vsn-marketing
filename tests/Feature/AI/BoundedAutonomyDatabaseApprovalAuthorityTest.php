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

function offlineApprovalDbFixture(): array
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Autonomy approval fixture', 'slug' => 'offline-approve-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org, 'name' => 'Approval workspace',
        'slug' => 'offline-approve-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $requester = User::query()->create([
        'name' => 'Requester', 'email' => Str::random(8).'@example.test', 'password' => 'unit-test-pass',
    ]);
    $approver = User::query()->create([
        'name' => 'Independent approver', 'email' => Str::random(8).'@example.test', 'password' => 'unit-test-pass',
    ]);
    $roles = new WorkspaceRoleManager;
    $member = $roles->addMember($approver, $workspace);
    $role = $roles->createRole($workspace, 'approver', 'Offline approver');
    $roles->grantPermission($role, PermissionCatalog::AI_APPROVE);
    $roles->assignRole($member, $role);

    return [
        'scope' => new TenantContext($org, $workspace, null, (string) $requester->getKey()),
        'approver' => $approver, 'member' => $member, 'role' => $role,
    ];
}

function offlineApprovalDbAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
}

function offlineApprovalDbPreview(TenantContext $scope): array
{
    $at = offlineApprovalDbAt();

    return (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['trusted-source'], ['count'], 1,
    ))->preview($scope, 'run-1', [
        'workspace_id' => $scope->workspaceId, 'brand_id' => null,
        'policy_version' => 'v1', 'purpose' => 'campaign_optimization',
        'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['trusted-source'], 'reason_code' => 'metric_review',
    ]], $at);
}

function offlineApprovalDbBinding(): array
{
    $at = offlineApprovalDbAt();

    return [
        'audience_sha256' => str_repeat('b', 64),
        'content_sha256' => str_repeat('c', 64),
        'destination_sha256' => str_repeat('d', 64),
        'max_cost_minor' => 50, 'max_volume' => 100,
        'not_before_unix' => $at->getTimestamp() - 60,
        'expires_at_unix' => $at->getTimestamp() + 300,
    ];
}

function offlineApprovalDbInsert(array $fixture, array $preview, array $patch = []): void
{
    $scope = $fixture['scope'];
    $at = offlineApprovalDbAt();
    DB::table('ai_autonomy_approval_decisions')->insert(array_replace([
        'id' => (string) Str::uuid(),
        'workspace_id' => $scope->workspaceId,
        'brand_id' => null,
        'run_id' => 'run-1',
        'snapshot_sha256' => $preview['snapshot_sha256'],
        'policy_version' => 'v1',
        ...offlineApprovalDbBinding(),
        'approved_at_unix' => $at->getTimestamp() - 30,
        'approver_id' => (string) $fixture['approver']->getKey(),
        'outcome' => 'approved',
        'created_at' => now(), 'updated_at' => now(),
    ], $patch));
}

it('allows only currently authorized independent approver evidence for offline review', function () {
    $f = offlineApprovalDbFixture();
    $scope = $f['scope'];
    $preview = offlineApprovalDbPreview($scope);
    $source = new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class));

    expect($source->latest($scope, 'run-1', offlineApprovalDbAt()))->toBeNull();
    offlineApprovalDbInsert($f, $preview);
    $current = $source->latest($scope, 'run-1', offlineApprovalDbAt());
    expect($current)->not->toBeNull()
        ->and($current['outcome'])->toBe('approved')
        ->and($current['approver_id'])->toBe((string) $f['approver']->getKey());

    $review = (new BoundedAutonomyOfflineApprovalReview($source))
        ->inspect($scope, $preview, offlineApprovalDbBinding(), offlineApprovalDbAt());
    expect($review['status'])->toBe('approval_matched_offline')
        ->and($review['execution_authorized'])->toBeFalse()
        ->and($review['promotion_authorized'])->toBeFalse();

    DB::table('workspace_role_permissions')->where('workspace_role_id', $f['role'])
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    expect($source->latest($scope, 'run-1', offlineApprovalDbAt()))->toBeNull();
    $held = (new BoundedAutonomyOfflineApprovalReview($source))
        ->inspect($scope, $preview, offlineApprovalDbBinding(), offlineApprovalDbAt());
    expect($held['status'])->toBe('approval_held_offline')
        ->and($held['reason_code'])->toBe('independent_approval_unavailable');
});

it('latest decision cannot be bypassed by old approval, foreign organization or mutated binding', function () {
    $f = offlineApprovalDbFixture();
    $scope = $f['scope'];
    $preview = offlineApprovalDbPreview($scope);
    offlineApprovalDbInsert($f, $preview);
    $source = new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class));
    $reviewer = new BoundedAutonomyOfflineApprovalReview($source);

    // Newer negative decision overrides any previously accepted approval.
    offlineApprovalDbInsert($f, $preview, [
        'approved_at_unix' => offlineApprovalDbAt()->getTimestamp() - 1,
        'outcome' => 'revoked',
    ]);
    $latest = $reviewer->inspect($scope, $preview, offlineApprovalDbBinding(), offlineApprovalDbAt());
    expect($latest['status'])->toBe('approval_held_offline')
        ->and($latest['reason_code'])->toBe('approval_revoked');

    expect($source->latest(new TenantContext((string) Str::uuid(),
        $scope->workspaceId, null, $scope->actorId), 'run-1', offlineApprovalDbAt()))->toBeNull();

    offlineApprovalDbInsert($f, $preview, [
        'approved_at_unix' => offlineApprovalDbAt()->getTimestamp(),
        'content_sha256' => str_repeat('e', 64),
    ]);
    $changed = $reviewer->inspect($scope, $preview, offlineApprovalDbBinding(), offlineApprovalDbAt());
    expect($changed['status'])->toBe('approval_held_offline')
        ->and($changed['reason_code'])->toBe('approval_binding_changed');
});

it('database review never manufactures approver permissions or production actions', function () {
    $f = offlineApprovalDbFixture();
    $scope = $f['scope'];
    $preview = offlineApprovalDbPreview($scope);
    $source = new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class));

    // A decision referring to the requesting actor cannot be an independent
    // permission grant. The source rechecks canonical role assignment.
    offlineApprovalDbInsert($f, $preview, ['approver_id' => $scope->actorId]);
    $r = (new BoundedAutonomyOfflineApprovalReview($source))
        ->inspect($scope, $preview, offlineApprovalDbBinding(), offlineApprovalDbAt());
    expect($r['status'])->toBe('approval_held_offline')
        ->and($r['execution_authorized'])->toBeFalse()
        ->and($r['promotion_authorized'])->toBeFalse();

    expect($source->latest($scope, 'invalid/run/path', offlineApprovalDbAt()))->toBeNull();
});
