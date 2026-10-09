<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineOutcomeJoinReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function offlineOutcomeJoinFixture(): array
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Join fixture', 'slug' => 'offline-join-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org, 'name' => 'Join workspace',
        'slug' => 'offline-join-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $actor = new TenantContext($org, $workspace, null, 'operator');
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'canary_review', 'company',
        ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000], 'control', 'holdout');
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $analysis = new ExperimentAnalysisPlan((string) Str::uuid(), 'company', $plan->weights,
        'control', 'holdout', 0.05, 0.8, 0.2, 0.05, $at->modify('+2 days'));
    DB::table('experiments')->insert([
        'id' => $plan->id, 'workspace_id' => $workspace, 'brand_id' => null,
        'layer' => $plan->layer, 'unit_kind' => $plan->unitKind,
        'control_variant' => $plan->control, 'holdout_variant' => $plan->holdout,
        'allocation' => json_encode($plan->canonical()['weights'], JSON_THROW_ON_ERROR),
        'plan_hash' => $plan->fingerprint(), 'key_fingerprint' => str_repeat('a', 64),
        'status' => 'active', 'created_by_actor_id' => 'creator',
        'approved_by_actor_id' => 'owner', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $receipt = (string) Str::uuid();

    return [$actor, $plan, $analysis, $at, $receipt];
}

function offlineOutcomeJoinRecord(TenantContext $actor, ExperimentPlan $plan, string $id): void
{
    DB::table('ai_autonomy_offline_canary_reviews')->insert([
        'id' => $id, 'workspace_id' => $actor->workspaceId,
        'experiment_id' => $plan->id, 'brand_id' => $actor->brandId,
        'plan_sha256' => $plan->fingerprint(),
        'assignment_manifest_sha256' => str_repeat('b', 64),
        'assigned_denominator' => 1000, 'holdout_denominator' => 100,
        'status' => 'offline_review_only', 'recorded_by_actor_id' => 'operator',
        'created_at' => now(),
    ]);
}

function offlineOutcomeJoinFacts(
    TenantContext $actor,
    ExperimentPlan $plan,
    ExperimentAnalysisPlan $analysis,
    DateTimeImmutable $at,
    string $receipt,
): array {
    return [
        'tenant' => $actor->toArray(),
        'experiment_id' => $plan->id,
        'plan_sha256' => $plan->fingerprint(),
        'analysis_sha256' => $analysis->fingerprint(),
        'cohort_receipt_id' => $receipt,
        'cohort_manifest_sha256' => str_repeat('b', 64),
        'outcome_manifest_sha256' => str_repeat('c', 64),
        'observed_at_unix' => $at->getTimestamp(),
        'expires_at_unix' => $at->getTimestamp() + 300,
        'verified_independent_join' => true, 'consent_verified' => true,
        'quarantined' => 0, 'crossovers' => 0, 'duplicate_events' => 0,
    ];
}

function offlineOutcomeJoinService(?object $facts, bool $allowed = true): BoundedAutonomyOfflineOutcomeJoinReview
{
    $source = $facts === null ? new DenyingBoundedAutonomyCanaryOutcomeJoinSource
        : new class($facts) implements BoundedAutonomyCanaryOutcomeJoinSource
        {
            public function __construct(private object $facts) {}

            public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
            {
                return $this->facts->value;
            }
        };

    $access = new class($allowed) implements ExperimentAccess
    {
        public function __construct(private bool $allowed) {}

        public function allows(TenantContext $actor, string $permission): bool
        {
            return $this->allowed && $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };

    return new BoundedAutonomyOfflineOutcomeJoinReview(
        $source, new DenyingBoundedAutonomyCanaryOutcomeSource, $access,
    );
}

it('requires a durable frozen cohort receipt before an independent outcome join is considered', function () {
    [$actor, $plan, $analysis, $at, $receipt] = offlineOutcomeJoinFixture();
    $facts = (object) ['value' => offlineOutcomeJoinFacts($actor, $plan, $analysis, $at, $receipt)];
    $service = offlineOutcomeJoinService($facts);
    $missing = $service->inspect($actor, $plan, $analysis, $at);
    expect($missing['reason_code'])->toBe('frozen_cohort_receipt_unavailable')
        ->and($missing['promotion_authorized'])->toBeFalse();

    offlineOutcomeJoinRecord($actor, $plan, $receipt);
    $noIndependentSource = offlineOutcomeJoinService(null)->inspect($actor, $plan, $analysis, $at);
    expect($noIndependentSource['reason_code'])->toBe('independent_outcome_join_unavailable')
        ->and($noIndependentSource['execution_authorized'])->toBeFalse();

    $joinedButNoOutcome = $service->inspect($actor, $plan, $analysis, $at);
    expect($joinedButNoOutcome['status'])->toBe('held_offline')
        ->and($joinedButNoOutcome['reason_code'])->toBe('independent_outcome_unavailable')
        ->and($joinedButNoOutcome['external_outcome_proven'])->toBeFalse();
});

it('rejects fabricated cohort receipt IDs and mismatched outcome joins', function () {
    [$actor, $plan, $analysis, $at, $receipt] = offlineOutcomeJoinFixture();
    offlineOutcomeJoinRecord($actor, $plan, $receipt);
    $facts = (object) ['value' => offlineOutcomeJoinFacts($actor, $plan, $analysis, $at, $receipt)];
    $service = offlineOutcomeJoinService($facts);
    foreach ([
        ['cohort_receipt_id' => (string) Str::uuid()],
        ['cohort_manifest_sha256' => str_repeat('e', 64)],
        ['analysis_sha256' => str_repeat('d', 64)],
        ['tenant' => (new TenantContext('foreign', $actor->workspaceId, null, 'operator'))->toArray()],
        ['observed_at_unix' => $at->getTimestamp() - 301],
        ['promotion_authorized' => true],
    ] as $tamper) {
        $facts->value = array_replace(offlineOutcomeJoinFacts($actor, $plan, $analysis, $at, $receipt), $tamper);
        expect(fn () => $service->inspect($actor, $plan, $analysis, $at))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('holds contaminated outcome joins and stopped experiments and rechecks operator permission', function () {
    [$actor, $plan, $analysis, $at, $receipt] = offlineOutcomeJoinFixture();
    offlineOutcomeJoinRecord($actor, $plan, $receipt);
    $facts = (object) ['value' => offlineOutcomeJoinFacts($actor, $plan, $analysis, $at, $receipt)];
    $facts->value['duplicate_events'] = 1;
    $result = offlineOutcomeJoinService($facts)->inspect($actor, $plan, $analysis, $at);
    expect($result['reason_code'])->toBe('unverified_or_contaminated_outcome_join')
        ->and($result['promotion_authorized'])->toBeFalse();

    expect(fn () => offlineOutcomeJoinService($facts, false)->inspect($actor, $plan, $analysis, $at))
        ->toThrow(InvalidArgumentException::class);
    DB::table('experiments')->where('id', $plan->id)->update(['status' => 'stopped']);
    $held = offlineOutcomeJoinService($facts)->inspect($actor, $plan, $analysis, $at);
    expect($held['reason_code'])->toBe('frozen_experiment_authority_unavailable');
    $foreign = new TenantContext((string) Str::uuid(), $actor->workspaceId, null, 'operator');
    expect(fn () => offlineOutcomeJoinService($facts)->inspect($foreign, $plan, $analysis, $at))
        ->toThrow(InvalidArgumentException::class);
});
