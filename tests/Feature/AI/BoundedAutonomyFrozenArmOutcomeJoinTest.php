<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineOutcomeJoinReview;
use App\Modules\AI\Domain\BoundedAutonomyCanaryOutcomeEvidenceDigest;
use App\Modules\AI\Domain\BoundedAutonomyArmEvidenceDigest;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('refuses a positive offline cohort review unless independently frozen arm counts agree', function () {
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    $experimentId = (string) Str::uuid();
    $receiptId = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-10-11T09:00:00+00:00');
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Frozen-arm fixture', 'slug' => 'frozen-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org, 'name' => 'Frozen workspace',
        'slug' => 'frozen-'.Str::random(12), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $actor = new TenantContext($org, $workspace, null, 'operator');
    $plan = new ExperimentPlan(
        $experimentId, $workspace, null, 'frozen_arm_review', 'company',
        ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000], 'control', 'holdout',
    );
    $analysis = new ExperimentAnalysisPlan(
        (string) Str::uuid(), 'company', $plan->weights,
        'control', 'holdout', 0.05, 0.8, 0.2, 0.15, $at->modify('-1 day'),
    );
    DB::table('experiments')->insert([
        'id' => $experimentId, 'workspace_id' => $workspace, 'brand_id' => null,
        'layer' => $plan->layer, 'unit_kind' => $plan->unitKind,
        'control_variant' => $plan->control, 'holdout_variant' => $plan->holdout,
        'allocation' => json_encode($plan->canonical()['weights'], JSON_THROW_ON_ERROR),
        'plan_hash' => $plan->fingerprint(), 'key_fingerprint' => str_repeat('a', 64),
        'status' => 'active', 'created_by_actor_id' => 'creator',
        'approved_by_actor_id' => 'owner', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_offline_canary_reviews')->insert([
        'id' => $receiptId, 'workspace_id' => $workspace, 'experiment_id' => $experimentId,
        'brand_id' => null, 'plan_sha256' => $plan->fingerprint(),
        'assignment_manifest_sha256' => str_repeat('b', 64),
        'assignment_arm_set_sha256' => BoundedAutonomyArmEvidenceDigest::sha256(
            $plan->fingerprint(), ['control' => 5000, 'treatment' => 5000, 'holdout' => 1000],
        ),
        'assigned_denominator' => 11000, 'holdout_denominator' => 1000,
        'status' => 'offline_review_only', 'recorded_by_actor_id' => 'operator',
        'created_at' => now(),
    ]);
    $joined = [
        'tenant' => $actor->toArray(), 'experiment_id' => $experimentId,
        'plan_sha256' => $plan->fingerprint(), 'analysis_sha256' => $analysis->fingerprint(),
        'cohort_receipt_id' => $receiptId, 'cohort_manifest_sha256' => str_repeat('b', 64),
        'outcome_manifest_sha256' => str_repeat('c', 64),
        'observed_at_unix' => $at->getTimestamp(), 'expires_at_unix' => $at->getTimestamp() + 300,
        'verified_independent_join' => true, 'consent_verified' => true,
        'quarantined' => 0, 'crossovers' => 0, 'duplicate_events' => 0,
    ];
    $scores = [
        'tenant' => $actor->toArray(), 'experiment_id' => $experimentId,
        'plan_sha256' => $plan->fingerprint(), 'analysis_sha256' => $analysis->fingerprint(),
        'source_manifest_sha256' => str_repeat('c', 64),
        'observed_at_unix' => $at->getTimestamp(), 'expires_at_unix' => $at->getTimestamp() + 300,
        'verified_independent_source' => true,
        'assigned' => ['control' => 5000, 'treatment' => 5000, 'holdout' => 1000],
        'exposed' => ['control' => 5000, 'treatment' => 5000, 'holdout' => 0],
        'outcomes' => ['control' => 500, 'treatment' => 2000, 'holdout' => 0],
        'quarantined' => 0, 'crossovers' => 0, 'low_trust_count' => 0,
    ];
    $scores['source_manifest_sha256'] = BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256($scores);
    $joined['outcome_manifest_sha256'] = $scores['source_manifest_sha256'];
    $cohort = [
        'tenant' => $actor->toArray(), 'plan_sha256' => $plan->fingerprint(),
        'assignment_manifest_sha256' => str_repeat('b', 64),
        'observed_at_unix' => $at->getTimestamp(), 'expires_at_unix' => $at->getTimestamp() + 300,
        'frozen' => true, 'consent_verified' => true, 'quarantined' => 0, 'crossovers' => 0,
        'counts' => [
            'control' => ['eligible' => 5000, 'assigned' => 5000, 'exposed' => 5000],
            'treatment' => ['eligible' => 5000, 'assigned' => 5000, 'exposed' => 5000],
            'holdout' => ['eligible' => 1000, 'assigned' => 1000, 'exposed' => 0],
        ],
    ];
    $join = new class($joined) implements BoundedAutonomyCanaryOutcomeJoinSource
    {
        public function __construct(public array $facts) {}

        public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
        {
            return $this->facts;
        }
    };
    $outcome = new class($scores) implements BoundedAutonomyCanaryOutcomeSource
    {
        public function __construct(public array $facts) {}

        public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
        {
            return $this->facts;
        }
    };
    $cohorts = new class($cohort) implements BoundedAutonomyCanaryCohortSource
    {
        public function __construct(public array $facts) {}

        public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
        {
            return $this->facts;
        }
    };
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };
    $withoutReadback = (new BoundedAutonomyOfflineOutcomeJoinReview($join, $outcome, $access))
        ->inspect($actor, $plan, $analysis, $at);
    expect($withoutReadback['status'])->toBe('held_offline')
        ->and($withoutReadback['reason_code'])->toBe('independent_cohort_arm_readback_unavailable');

    $reviewer = new BoundedAutonomyOfflineOutcomeJoinReview($join, $outcome, $access, $cohorts);
    $matched = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($matched['status'])->toBe('offline_joined_operator_review_candidate')
        ->and($matched['promotion_authorized'])->toBeFalse()
        ->and($matched['execution_authorized'])->toBeFalse();

    // Historical pre-migration receipts retain their evidence but cannot
    // fabricate a per-arm commitment at the time of an operator review.
    DB::table('ai_autonomy_offline_canary_reviews')->where('id', $receiptId)
        ->update(['assignment_arm_set_sha256' => null]);
    $legacy = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($legacy['status'])->toBe('held_offline')
        ->and($legacy['reason_code'])->toBe('frozen_cohort_arm_commitment_unavailable')
        ->and($legacy['promotion_authorized'])->toBeFalse();

    DB::table('ai_autonomy_offline_canary_reviews')->where('id', $receiptId)
        ->update(['assignment_arm_set_sha256' => str_repeat('0', 64)]);
    $tampered = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($tampered['status'])->toBe('held_offline')
        ->and($tampered['reason_code'])->toBe('frozen_cohort_arm_commitment_mismatch')
        ->and($tampered['execution_authorized'])->toBeFalse();

    DB::table('ai_autonomy_offline_canary_reviews')->where('id', $receiptId)
        ->update(['assignment_arm_set_sha256' => BoundedAutonomyArmEvidenceDigest::sha256(
            $plan->fingerprint(), ['control' => 5000, 'treatment' => 5000, 'holdout' => 1000],
        )]);

    // The independent outcome provider reports full exposures while the
    // frozen consent cohort says one assignment was not exposed.
    $cohorts->facts['counts']['control']['exposed'] = 4999;
    $exposureMismatch = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($exposureMismatch['status'])->toBe('held_offline')
        ->and($exposureMismatch['reason_code'])->toBe('cohort_outcome_exposure_mismatch')
        ->and($exposureMismatch['promotion_authorized'])->toBeFalse()
        ->and($exposureMismatch['execution_authorized'])->toBeFalse();
    $cohorts->facts['counts']['control']['exposed'] = 5000;

    // Even two fresh sources cannot use a cohort consent observation from
    // before the independent scored-outcome observation as current consent.
    $cohorts->facts['observed_at_unix'] = $at->getTimestamp() - 1;
    $olderConsent = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($olderConsent['status'])->toBe('held_offline')
        ->and($olderConsent['reason_code'])->toBe('cohort_consent_readback_predates_outcomes')
        ->and($olderConsent['promotion_authorized'])->toBeFalse();
    $cohorts->facts['observed_at_unix'] = $at->getTimestamp();

    // A join observation before the independently scored snapshot also
    // cannot be called an independently verified joined review.
    $join->facts['observed_at_unix'] = $at->getTimestamp() - 1;
    $olderJoin = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($olderJoin['status'])->toBe('held_offline')
        ->and($olderJoin['reason_code'])->toBe('outcome_join_predates_scored_snapshot')
        ->and($olderJoin['execution_authorized'])->toBeFalse();
    $join->facts['observed_at_unix'] = $at->getTimestamp();

    // The same total and holdout can mask moved units between treatment/control.
    $outcome->facts['assigned'] = ['control' => 4999, 'treatment' => 5001, 'holdout' => 1000];
    $outcome->facts['exposed'] = ['control' => 4999, 'treatment' => 5001, 'holdout' => 0];
    $outcome->facts['source_manifest_sha256'] = BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256($outcome->facts);
    $join->facts['outcome_manifest_sha256'] = $outcome->facts['source_manifest_sha256'];
    $mismatch = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($mismatch['status'])->toBe('held_offline')
        ->and($mismatch['reason_code'])->toBe('cohort_outcome_arm_denominator_mismatch')
        ->and($mismatch['promotion_authorized'])->toBeFalse()
        ->and($mismatch['execution_authorized'])->toBeFalse();
});
