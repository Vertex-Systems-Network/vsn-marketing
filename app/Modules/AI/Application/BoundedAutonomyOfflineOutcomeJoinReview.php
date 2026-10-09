<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only binding from durable frozen-cohort receipt to independently
 * verified outcome provenance. A statistical signal is operator advice, not
 * a conversion claim, campaign enrollment or authorization to promote.
 */
final readonly class BoundedAutonomyOfflineOutcomeJoinReview
{
    private const array EVIDENCE_KEYS = [
        'tenant', 'experiment_id', 'plan_sha256', 'analysis_sha256',
        'cohort_receipt_id', 'cohort_manifest_sha256',
        'outcome_manifest_sha256', 'observed_at_unix', 'expires_at_unix',
        'verified_independent_join', 'consent_verified', 'quarantined',
        'crossovers', 'duplicate_events',
    ];

    public function __construct(
        private BoundedAutonomyCanaryOutcomeJoinSource $joins,
        private BoundedAutonomyCanaryOutcomeSource $outcomes,
        private ExperimentAccess $access,
    ) {}

    public function inspect(
        TenantContext $actor,
        ExperimentPlan $experiment,
        ExperimentAnalysisPlan $analysis,
        DateTimeImmutable $at,
    ): array {
        if (! $this->access->allows($actor, PermissionCatalog::CAMPAIGN_READ)
            || $actor->actorId === ''
            || $experiment->workspaceId !== $actor->workspaceId
            || $experiment->brandId !== $actor->brandId
            || $analysis->weights !== $experiment->weights
            || $analysis->unitKind !== $experiment->unitKind
            || $analysis->control !== $experiment->control
            || $analysis->holdout !== $experiment->holdout) {
            throw new InvalidArgumentException('Untrusted canary outcome join or operator permission.');
        }

        return DB::transaction(function () use ($actor, $experiment, $analysis, $at): array {
            if (! DB::table('workspaces')
                ->where('id', $actor->workspaceId)
                ->where('organization_id', $actor->organizationId)->exists()) {
                throw new InvalidArgumentException('Foreign canary organization denied.');
            }
            $canonical = DB::table('experiments')
                ->where('id', $experiment->id)
                ->where('workspace_id', $actor->workspaceId)
                ->where('brand_id', $actor->brandId)
                ->first();
            if ($canonical === null || $canonical->status !== 'active'
                || $canonical->plan_hash !== $experiment->fingerprint()
                || $canonical->approved_by_actor_id === null
                || $canonical->approved_by_actor_id === $canonical->created_by_actor_id) {
                return $this->hold($experiment, 'frozen_experiment_authority_unavailable');
            }

            $receipt = DB::table('ai_autonomy_offline_canary_reviews')
                ->where('workspace_id', $actor->workspaceId)
                ->where('experiment_id', $experiment->id)
                ->where('brand_id', $actor->brandId)->first();

            if ($receipt === null || $receipt->status !== 'offline_review_only'
                || $receipt->plan_sha256 !== $experiment->fingerprint()
                || ! self::digest($receipt->assignment_manifest_sha256)) {
                return $this->hold($experiment, 'frozen_cohort_receipt_unavailable');
            }

            $join = $this->joins->latest($actor, $experiment->id, $at);
            if ($join === null) {
                return $this->hold($experiment, 'independent_outcome_join_unavailable');
            }
            self::keys($join, self::EVIDENCE_KEYS);
            if ($join['tenant'] !== $actor->toArray()
                || $join['experiment_id'] !== $experiment->id
                || $join['plan_sha256'] !== $experiment->fingerprint()
                || $join['analysis_sha256'] !== $analysis->fingerprint()
                || $join['cohort_receipt_id'] !== $receipt->id
                || $join['cohort_manifest_sha256'] !== $receipt->assignment_manifest_sha256
                || ! self::digest($join['outcome_manifest_sha256'])
                || ! is_int($join['observed_at_unix'])
                || ! is_int($join['expires_at_unix'])
                || $join['observed_at_unix'] > $at->getTimestamp()
                || $join['observed_at_unix'] < $at->getTimestamp() - 300
                || $join['expires_at_unix'] <= $at->getTimestamp()
                || $join['expires_at_unix'] > $at->getTimestamp() + 3600) {
                throw new InvalidArgumentException('Independent outcome join does not match frozen evidence.');
            }

            foreach (['quarantined', 'crossovers', 'duplicate_events'] as $key) {
                if (! is_int($join[$key]) || $join[$key] < 0 || $join[$key] > 1000000) {
                    throw new InvalidArgumentException('Untrusted canary outcome join diagnostics.');
                }
            }
            if ($join['verified_independent_join'] !== true
                || $join['consent_verified'] !== true
                || $join['quarantined'] > 0
                || $join['crossovers'] > 0
                || $join['duplicate_events'] > 0) {
                return $this->hold($experiment, 'unverified_or_contaminated_outcome_join');
            }

            $score = (new BoundedAutonomyOfflineCanaryScore($this->outcomes))->inspect(
                $actor, $experiment, $analysis, $at,
            );
            if ($score['source_manifest_sha256'] === null) {
                return $this->hold($experiment, $score['reason_code']);
            }
            if ($score['source_manifest_sha256'] !== $join['outcome_manifest_sha256']) {
                return $this->hold($experiment, 'outcome_source_manifest_drift');
            }

            return [
                'status' => $score['status'] === 'offline_operator_review_candidate'
                    ? 'offline_joined_operator_review_candidate' : 'held_offline',
                'reason_code' => $score['status'] === 'offline_operator_review_candidate'
                    ? 'independent_human_promotion_gate_required' : $score['reason_code'],
                'experiment_id' => $experiment->id,
                'cohort_receipt_id' => $receipt->id,
                'plan_sha256' => $experiment->fingerprint(),
                'analysis_sha256' => $analysis->fingerprint(),
                'outcome_manifest_sha256' => $join['outcome_manifest_sha256'],
                'execution_authorized' => false,
                'promotion_authorized' => false,
                'external_outcome_proven' => false,
            ];
        }, 3);
    }

    private function hold(ExperimentPlan $experiment, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'experiment_id' => $experiment->id,
            'cohort_receipt_id' => null,
            'plan_sha256' => $experiment->fingerprint(),
            'analysis_sha256' => null,
            'outcome_manifest_sha256' => null,
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'external_outcome_proven' => false,
        ];
    }

    private static function keys(array $data, array $keys): void
    {
        if (count($data) !== count($keys)
            || array_diff(array_keys($data), $keys) !== []
            || array_diff($keys, array_keys($data)) !== []) {
            throw new InvalidArgumentException('Unregistered independent outcome join fields.');
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
