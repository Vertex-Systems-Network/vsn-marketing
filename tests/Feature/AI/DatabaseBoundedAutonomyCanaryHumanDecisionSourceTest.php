<?php

use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function offlineCanaryHumanDbFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Human canary fixture',
        'slug' => 'human-canary-'.Str::random(9), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Human canary workspace',
        'slug' => 'human-canary-'.Str::random(9), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $requester = User::query()->create([
        'name' => 'Requester', 'email' => Str::random(10).'@example.org',
        'password' => Str::random(24),
    ]);
    $approver = User::query()->create([
        'name' => 'Independent human', 'email' => Str::random(10).'@example.org',
        'password' => Str::random(24),
    ]);
    $roleManager = app(WorkspaceRoleManager::class);
    $membership = $roleManager->addMember($approver, $ws);
    $role = $roleManager->createRole($ws, 'human-canary-approver', 'Independent canary approver');
    $roleManager->assignRole($membership, $role);

    $scope = new TenantContext($org, $ws, null, (string) $requester->getKey());
    $plan = new ExperimentPlan((string) Str::uuid(), $ws, null, 'human_canary_review',
        'company', ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000], 'control', 'holdout');

    DB::table('experiments')->insert([
        'id' => $plan->id, 'workspace_id' => $ws, 'brand_id' => null,
        'layer' => $plan->layer, 'unit_kind' => $plan->unitKind,
        'control_variant' => $plan->control, 'holdout_variant' => $plan->holdout,
        'allocation' => json_encode($plan->canonical()['weights'], JSON_THROW_ON_ERROR),
        'plan_hash' => $plan->fingerprint(), 'key_fingerprint' => str_repeat('a', 64),
        'status' => 'active', 'created_by_actor_id' => 'creator',
        'approved_by_actor_id' => 'human-owner', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('scope', 'plan', 'approver', 'requester', 'role');
}

function offlineCanaryHumanDecision(array $fixture, array $patch = []): void
{
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    DB::table('ai_autonomy_canary_human_decisions')->insert(array_replace([
        'decision_id' => (string) Str::uuid(),
        'workspace_id' => $fixture['scope']->workspaceId,
        'brand_id' => null,
        'experiment_id' => $fixture['plan']->id,
        'sequence' => 1,
        'plan_sha256' => $fixture['plan']->fingerprint(),
        'analysis_sha256' => str_repeat('b', 64),
        'cohort_receipt_id' => (string) Str::uuid(),
        'outcome_manifest_sha256' => str_repeat('c', 64),
        'deciding_actor_id' => (string) $fixture['approver']->getKey(),
        'outcome' => 'approved', 'policy_version' => 'v1',
        'human_session_verified' => true,
        'session_proof_sha256' => str_repeat('d', 64),
        'observed_at_unix' => $at->getTimestamp(),
        'expires_at_unix' => $at->getTimestamp() + 300,
        'created_at' => now(),
    ], $patch));
}

it('requires durable session evidence and both current independent workspace permissions', function () {
    $f = offlineCanaryHumanDbFixture();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $source = new DatabaseBoundedAutonomyCanaryHumanDecisionSource(app(WorkspaceAuthorizer::class));
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();

    offlineCanaryHumanDecision($f);
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();

    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();

    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::CAMPAIGN_APPROVE);
    $value = $source->latest($f['scope'], $f['plan']->id, $at);
    expect($value['decision_id'])->not->toBeNull()
        ->and($value['current_permission_verified'])->toBeTrue()
        ->and($value['authenticated_human'])->toBeTrue();

    DB::table('workspace_role_permissions')->where('workspace_role_id', $f['role'])
        ->where('permission', PermissionCatalog::CAMPAIGN_APPROVE)->delete();
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();
});

it('rejects foreign organization, forged session evidence and invalid canonical experiment authority', function () {
    $f = offlineCanaryHumanDbFixture();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::CAMPAIGN_APPROVE);
    offlineCanaryHumanDecision($f, ['human_session_verified' => false]);
    $source = new DatabaseBoundedAutonomyCanaryHumanDecisionSource(app(WorkspaceAuthorizer::class));

    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();
    DB::table('ai_autonomy_canary_human_decisions')->where('experiment_id', $f['plan']->id)
        ->update(['human_session_verified' => true, 'session_proof_sha256' => 'invalid']);
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();

    DB::table('ai_autonomy_canary_human_decisions')->where('experiment_id', $f['plan']->id)
        ->update(['session_proof_sha256' => str_repeat('d', 64)]);
    $foreign = new TenantContext((string) Str::uuid(), $f['scope']->workspaceId, null, $f['scope']->actorId);
    expect($source->latest($foreign, $f['plan']->id, $at))->toBeNull();

    DB::table('ai_autonomy_canary_human_decisions')->where('experiment_id', $f['plan']->id)
        ->update(['observed_at_unix' => $at->getTimestamp() + 1]);
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();
    DB::table('ai_autonomy_canary_human_decisions')->where('experiment_id', $f['plan']->id)
        ->update(['observed_at_unix' => $at->getTimestamp(), 'expires_at_unix' => $at->getTimestamp()]);
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();
    DB::table('ai_autonomy_canary_human_decisions')->where('experiment_id', $f['plan']->id)
        ->update(['expires_at_unix' => $at->getTimestamp() + 300]);

    DB::table('experiments')->where('id', $f['plan']->id)->update(['status' => 'draft']);
    expect($source->latest($f['scope'], $f['plan']->id, $at))->toBeNull();
});

it('latest revocation supersedes a prior positive human decision without enabling provider effects', function () {
    $f = offlineCanaryHumanDbFixture();
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::CAMPAIGN_APPROVE);
    offlineCanaryHumanDecision($f);
    $source = new DatabaseBoundedAutonomyCanaryHumanDecisionSource(app(WorkspaceAuthorizer::class));
    expect($source->latest($f['scope'], $f['plan']->id, $at)['outcome'])->toBe('approved');

    offlineCanaryHumanDecision($f, ['sequence' => 2, 'outcome' => 'revoked']);
    expect($source->latest($f['scope'], $f['plan']->id, $at)['outcome'])->toBe('revoked');
    $self = new TenantContext($f['scope']->organizationId, $f['scope']->workspaceId,
        null, (string) $f['approver']->getKey());
    expect($source->latest($self, $f['plan']->id, $at))->toBeNull();
});
