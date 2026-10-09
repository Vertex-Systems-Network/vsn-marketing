<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Both independent cohort and verified outcome sources must agree before an
 * OFFLINE operator review is proposed. This NEVER publishes or promotes.
 */
final readonly class BoundedAutonomyOfflinePromotionReview
{
    public function __construct(
        private BoundedAutonomyCanaryCohortSource $cohorts,
        private BoundedAutonomyCanaryOutcomeSource $outcomes,
    ) {}

    public function inspect(
        TenantContext $actor,
        ExperimentPlan $experiment,
        ExperimentAnalysisPlan $analysis,
        DateTimeImmutable $at,
    ): array {
        $cohort = (new BoundedAutonomyOfflineCanaryReview($this->cohorts))->inspect($actor, $experiment, $at);
        if ($cohort['status'] !== 'offline_cohort_review_ready') {
            return $this->hold($experiment, (string) $cohort['reason_code']);
        }

        $score = (new BoundedAutonomyOfflineCanaryScore($this->outcomes))->inspect(
            $actor, $experiment, $analysis, $at,
        );
        if ($score['status'] !== 'offline_operator_review_candidate') {
            return $this->hold($experiment, (string) $score['reason_code']);
        }
        if ($score['assignment_manifest_sha256'] !== $cohort['assignment_manifest_sha256']
            || $score['assigned_denominator'] !== $cohort['assigned_denominator']
            || $score['holdout_denominator'] !== $cohort['holdout_denominator']) {
            return $this->hold($experiment, 'independent_cohort_outcome_binding_mismatch');
        }

        return [
            'status' => 'offline_promotion_review_candidate',
            'reason_code' => 'human_and_external_promotion_authority_required',
            'experiment_id' => $experiment->id,
            'plan_sha256' => $experiment->fingerprint(),
            'assignment_manifest_sha256' => $cohort['assignment_manifest_sha256'],
            'outcome_manifest_sha256' => $score['source_manifest_sha256'],
            'holdout_denominator' => $cohort['holdout_denominator'],
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'publication_authorized' => false,
        ];
    }

    private function hold(ExperimentPlan $plan, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'experiment_id' => $plan->id,
            'plan_sha256' => $plan->fingerprint(),
            'assignment_manifest_sha256' => null,
            'outcome_manifest_sha256' => null,
            'holdout_denominator' => null,
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'publication_authorized' => false,
        ];
    }
}
