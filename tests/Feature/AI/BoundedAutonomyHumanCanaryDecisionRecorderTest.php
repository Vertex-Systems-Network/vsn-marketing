<?php

use App\Modules\AI\Application\BoundedAutonomyHumanCanaryDecisionRecorder;
use App\Modules\AI\Application\BoundedAutonomyOfflineHumanPromotionReview;
use App\Modules\AI\Application\BoundedAutonomyOfflineOutcomeJoinReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
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

function humanCanaryWriterFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Human promotion recorder',
        'slug' => 'human-recorder-'.Str::random(8), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Human recorder workspace',
        'slug' => 'human-recorder-'.Str::random(8), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $requester = User::query()->create([
        'name' => 'Canary requester', 'email' => Str::random(10).'@example.org',
        'password' => Str::random(25),
    ]);
    $approver = User::query()->create([
        'name' => 'Human canary approver', 'email' => Str::random(10).'@example.org',
        'password' => Str::random(25),
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($approver, $ws);
    $role = $roles->createRole($ws, 'human-canary-owner', 'Independent canary approver');
    $roles->assignRole($membership, $role);

    $scope = new TenantContext($org, $ws, null, (string) $requester->getKey());
    $plan = new ExperimentPlan((string) Str::uuid(), $ws, null, 'offline_human_review',
        'company', ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000], 'control', 'holdout');
    $analysis = new ExperimentAnalysisPlan((string) Str::uuid(), 'company',
        ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000],
        'control', 'holdout', 0.05, 0.8, 0.2, 0.15,
        new DateTimeImmutable('2026-10-08T09:00:00+00:00'));

    DB::table('experiments')->insert([
        'id' => $plan->id, 'workspace_id' => $ws, 'brand_id' => null,
        'layer' => $plan->layer, 'unit_kind' => $plan->unitKind,
        'control_variant' => $plan->control, 'holdout_variant' => $plan->holdout,
        'allocation' => json_encode($plan->canonical()['weights'], JSON_THROW_ON_ERROR),
        'plan_hash' => $plan->fingerprint(), 'key_fingerprint' => str_repeat('a', 64),
        'status' => 'active', 'created_by_actor_id' => 'creator',
        'approved_by_actor_id' => 'separate-human', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('scope', 'plan', 'analysis', 'requester', 'approver', 'role');
}

function humanCanaryWriterService(
    ?object $joinedFacts = null,
    ?object $outcomeFacts = null,
): BoundedAutonomyHumanCanaryDecisionRecorder {
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };
    $joins = $joinedFacts === null ? new DenyingBoundedAutonomyCanaryOutcomeJoinSource
        : new class($joinedFacts) implements BoundedAutonomyCanaryOutcomeJoinSource
        {
            public function __construct(private object $facts) {}

            public function latest(TenantContext $actor, string $experimentId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        };
    $outcomes = $outcomeFacts === null ? new DenyingBoundedAutonomyCanaryOutcomeSource
        : new class($outcomeFacts) implements BoundedAutonomyCanaryOutcomeSource
        {
            public function __construct(private object $facts) {}

            public function snapshot(TenantContext $actor, string $experimentId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        };

    return new BoundedAutonomyHumanCanaryDecisionRecorder(
        app(WorkspaceAuthorizer::class),
        new BoundedAutonomyOfflineOutcomeJoinReview($joins, $outcomes, $access),
    );
}

function humanCanaryGrantBoth(array $f): void
{
    $roles = app(WorkspaceRoleManager::class);
    $roles->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);
    $roles->grantPermission($f['role'], PermissionCatalog::CAMPAIGN_APPROVE);
}

function humanCanaryAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
}

it('refuses model-level approvals, absent human session and missing workspace permissions', function () {
    $f = humanCanaryWriterFixture();
    $writer = humanCanaryWriterService();
    expect(fn () => $writer->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt()))->toThrow(InvalidArgumentException::class);

    $this->actingAs($f['approver']);
    expect(fn () => $writer->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt()))->toThrow(InvalidArgumentException::class);

    humanCanaryGrantBoth($f);
    $held = $writer->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt());
    expect($held['reason_code'])->toBe('global_stop_unconfigured');
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $stopped = $writer->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt());
    expect($stopped['reason_code'])->toBe('workspace_emergency_stop')
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(0);
});

it('holds independent provider evidence gaps even with current human session and budget authority', function () {
    $f = humanCanaryWriterFixture();
    humanCanaryGrantBoth($f);
    $this->actingAs($f['approver']);
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $f['scope']->workspaceId,
        'period_utc' => humanCanaryAt()->format('Y-m-d'), 'policy_version' => 'v1',
        'workspace_stopped' => false, 'max_actions' => 1, 'max_tokens' => 100,
        'max_volume' => 100, 'max_cost_minor' => 100, 'max_attempts' => 1,
        'policy_expires_at' => humanCanaryAt()->modify('+1 hour')->format('Y-m-d H:i:s'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $r = humanCanaryWriterService()->record(
        $f['approver'], $f['scope'], $f['plan'], $f['analysis'], 'approved', humanCanaryAt(),
    );
    expect($r['status'])->toBe('held_offline')
        ->and($r['reason_code'])->toBe('independent_outcome_join_not_eligible')
        ->and($r['promotion_authorized'])->toBeFalse()
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(0);

    DB::table('ai_autonomy_workspace_quotas')
        ->where('workspace_id', $f['scope']->workspaceId)
        ->update(['policy_expires_at' => humanCanaryAt()->modify('-1 minute')->format('Y-m-d H:i:s')]);
    $expired = humanCanaryWriterService()->record(
        $f['approver'], $f['scope'], $f['plan'], $f['analysis'], 'approved', humanCanaryAt(),
    );
    expect($expired['reason_code'])->toBe('workspace_policy_expired')
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(0);
});

it('records independently joined human review only, never promoting or triggering provider side effects', function () {
    $f = humanCanaryWriterFixture();
    humanCanaryGrantBoth($f);
    $this->actingAs($f['approver']);
    $at = humanCanaryAt();
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $f['scope']->workspaceId,
        'period_utc' => $at->format('Y-m-d'), 'policy_version' => 'v1',
        'workspace_stopped' => false, 'max_actions' => 1, 'max_tokens' => 100,
        'max_volume' => 100, 'max_cost_minor' => 100, 'max_attempts' => 1,
        'policy_expires_at' => $at->modify('+1 hour')->format('Y-m-d H:i:s'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $receiptId = (string) Str::uuid();
    DB::table('ai_autonomy_offline_canary_reviews')->insert([
        'id' => $receiptId,
        'workspace_id' => $f['scope']->workspaceId,
        'experiment_id' => $f['plan']->id,
        'brand_id' => null,
        'plan_sha256' => $f['plan']->fingerprint(),
        'assignment_manifest_sha256' => str_repeat('b', 64),
        'assigned_denominator' => 11000,
        'holdout_denominator' => 1000,
        'status' => 'offline_review_only',
        'recorded_by_actor_id' => $f['scope']->actorId,
        'created_at' => now(),
    ]);

    $joined = (object) ['value' => [
        'tenant' => $f['scope']->toArray(),
        'experiment_id' => $f['plan']->id,
        'plan_sha256' => $f['plan']->fingerprint(),
        'analysis_sha256' => $f['analysis']->fingerprint(),
        'cohort_receipt_id' => $receiptId,
        'cohort_manifest_sha256' => str_repeat('b', 64),
        'outcome_manifest_sha256' => str_repeat('c', 64),
        'observed_at_unix' => $at->getTimestamp(),
        'expires_at_unix' => $at->getTimestamp() + 300,
        'verified_independent_join' => true,
        'consent_verified' => true,
        'quarantined' => 0,
        'crossovers' => 0,
        'duplicate_events' => 0,
    ]];
    $outcomes = (object) ['value' => [
        'tenant' => $f['scope']->toArray(),
        'experiment_id' => $f['plan']->id,
        'plan_sha256' => $f['plan']->fingerprint(),
        'analysis_sha256' => $f['analysis']->fingerprint(),
        'source_manifest_sha256' => str_repeat('c', 64),
        'observed_at_unix' => $at->getTimestamp(),
        'expires_at_unix' => $at->getTimestamp() + 300,
        'verified_independent_source' => true,
        'assigned' => ['control' => 5000, 'treatment' => 5000, 'holdout' => 1000],
        'exposed' => ['control' => 5000, 'treatment' => 5000, 'holdout' => 0],
        'outcomes' => ['control' => 500, 'treatment' => 2000, 'holdout' => 0],
        'quarantined' => 0, 'crossovers' => 0, 'low_trust_count' => 0,
    ]];
    $service = humanCanaryWriterService($joined, $outcomes);
    $recorded = $service->record($f['approver'], $f['scope'], $f['plan'], $f['analysis'],
        'approved', $at);
    expect($recorded['status'])->toBe('human_decision_recorded_offline')
        ->and($recorded['promotion_authorized'])->toBeFalse()
        ->and($recorded['execution_authorized'])->toBeFalse()
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(1);

    // Same human, immutable plan and independently recomputed outcome
    // cannot create a second positive decision under retries.
    $replayed = $service->record($f['approver'], $f['scope'], $f['plan'], $f['analysis'],
        'approved', $at);
    expect($replayed['status'])->toBe('human_decision_replayed_offline')
        ->and($replayed['decision_id'])->toBe($recorded['decision_id'])
        ->and($replayed['execution_authorized'])->toBeFalse()
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(1);

    DB::table('ai_autonomy_global_stops')->where('id', 'global')
        ->update(['stopped' => true]);
    $halted = $service->record($f['approver'], $f['scope'], $f['plan'], $f['analysis'],
        'approved', $at);
    expect($halted['status'])->toBe('held_offline')
        ->and($halted['reason_code'])->toBe('global_emergency_stop')
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(1);
    DB::table('ai_autonomy_global_stops')->where('id', 'global')
        ->update(['stopped' => false]);

    // A replay cannot outlive current human RBAC. Restoring the permission
    // re-enables only the offline evidence review, not any provider effect.
    DB::table('workspace_role_permissions')->where('workspace_role_id', $f['role'])
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    expect(fn () => $service->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'approved', $at))->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(1);
    app(WorkspaceRoleManager::class)->grantPermission($f['role'], PermissionCatalog::AI_APPROVE);

    $source = new DatabaseBoundedAutonomyCanaryHumanDecisionSource(app(WorkspaceAuthorizer::class));
    $fact = $source->latest($f['scope'], $f['plan']->id, $at);
    expect($fact['outcome'])->toBe('approved')
        ->and($fact['authenticated_human'])->toBeTrue();
    // Even a positive independent score and human review grants NO action.
    $score = (new BoundedAutonomyOfflineOutcomeJoinReview(
        new class($joined) implements BoundedAutonomyCanaryOutcomeJoinSource
        {
            public function __construct(private object $facts) {}

            public function latest(TenantContext $actor, string $experimentId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        },
        new class($outcomes) implements BoundedAutonomyCanaryOutcomeSource
        {
            public function __construct(private object $facts) {}

            public function snapshot(TenantContext $actor, string $experimentId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        },
        new class implements ExperimentAccess
        {
            public function allows(TenantContext $actor, string $permission): bool
            {
                return $permission === PermissionCatalog::CAMPAIGN_READ;
            }
        },
    ))->inspect($f['scope'], $f['plan'], $f['analysis'], $at);
    $review = (new BoundedAutonomyOfflineHumanPromotionReview($source))
        ->inspect($f['scope'], $f['plan'], $f['analysis'], $score, $at);
    expect($review['status'])->toBe('offline_human_review_evidence_ready')
        ->and($review['promotion_authorized'])->toBeFalse()
        ->and($review['execution_authorized'])->toBeFalse()
        ->and($review['external_outcome_proven'])->toBeFalse();
});

it('allows human revocation while global stop is active and never repeats evidence', function () {
    $f = humanCanaryWriterFixture();
    humanCanaryGrantBoth($f);
    $this->actingAs($f['approver']);
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = (string) Str::uuid();
    DB::table('ai_autonomy_canary_human_decisions')->insert([
        'decision_id' => $id,
        'workspace_id' => $f['scope']->workspaceId, 'brand_id' => null,
        'experiment_id' => $f['plan']->id, 'sequence' => 1,
        'plan_sha256' => $f['plan']->fingerprint(),
        'analysis_sha256' => $f['analysis']->fingerprint(),
        'cohort_receipt_id' => (string) Str::uuid(),
        'outcome_manifest_sha256' => str_repeat('a', 64),
        'deciding_actor_id' => (string) $f['approver']->getKey(),
        'outcome' => 'approved', 'policy_version' => 'v1',
        'human_session_verified' => true,
        'session_proof_sha256' => str_repeat('b', 64),
        'observed_at_unix' => humanCanaryAt()->getTimestamp(),
        'expires_at_unix' => humanCanaryAt()->getTimestamp() + 300,
        'created_at' => now(),
    ]);

    $writer = humanCanaryWriterService();
    $r = $writer->record($f['approver'], $f['scope'], $f['plan'], $f['analysis'],
        'revoked', humanCanaryAt());
    expect($r['status'])->toBe('human_decision_recorded_offline')
        ->and($r['outcome'])->toBe('revoked')
        ->and($r['promotion_authorized'])->toBeFalse();
    $again = $writer->record($f['approver'], $f['scope'], $f['plan'], $f['analysis'],
        'revoked', humanCanaryAt());
    expect($again['status'])->toBe('human_decision_replayed_offline')
        ->and(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(2);
});

it('denies self approval and unknown decisions without touching offline evidence', function () {
    $f = humanCanaryWriterFixture();
    humanCanaryGrantBoth($f);
    $this->actingAs($f['approver']);
    $self = new TenantContext($f['scope']->organizationId,
        $f['scope']->workspaceId, null, (string) $f['approver']->getKey());
    $writer = humanCanaryWriterService();
    expect(fn () => $writer->record($f['approver'], $self, $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $writer->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'automatic_publish', humanCanaryAt()))->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(0);

    $foreign = new TenantContext((string) Str::uuid(), $f['scope']->workspaceId,
        null, $f['scope']->actorId);
    expect(fn () => $writer->record($f['approver'], $foreign, $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt()))->toThrow(InvalidArgumentException::class);

    DB::table('workspace_role_permissions')->where('workspace_role_id', $f['role'])
        ->where('permission', PermissionCatalog::CAMPAIGN_APPROVE)->delete();
    expect(fn () => $writer->record($f['approver'], $f['scope'], $f['plan'],
        $f['analysis'], 'approved', humanCanaryAt()))->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_canary_human_decisions')->count())->toBe(0);
});
