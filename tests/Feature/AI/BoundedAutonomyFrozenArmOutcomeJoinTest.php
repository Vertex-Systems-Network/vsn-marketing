<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineOutcomeJoinReview;
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
    $join = new class($joined) implements BoundedAutonomyCanaryOutcomeJoinSource {
        public function __construct(public array $facts) {}
        public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
        { return $this->facts; }
    };
    $outcome = new class($scores) implements BoundedAutonomyCanaryOutcomeSource {
        public function __construct(public array $facts) {}
        public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
        { return $this->facts; }
    };
    $cohorts = new class($cohort) implements BoundedAutonomyCanaryCohortSource {
        public function __construct(public array $facts) {}
        public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
        { return $this->facts; }
    };
    $access = new class implements ExperimentAccess {
        public function allows(TenantContext $actor, string $permission): bool
        { return $permission === PermissionCatalog::CAMPAIGN_READ; }
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

    // The same total and holdout can mask moved units between treatment/control.
    $outcome->facts['assigned'] = ['control' => 4999, 'treatment' => 5001, 'holdout' => 1000];
    $outcome->facts['exposed'] = ['control' => 4999, 'treatment' => 5001, 'holdout' => 0];
    $mismatch = $reviewer->inspect($actor, $plan, $analysis, $at);
    expect($mismatch['status'])->toBe('held_offline')
        ->and($mismatch['reason_code'])->toBe('cohort_outcome_arm_denominator_mismatch')
        ->and($mismatch['promotion_authorized'])->toBeFalse()
        ->and($mismatch['execution_authorized'])->toBeFalse();
});
