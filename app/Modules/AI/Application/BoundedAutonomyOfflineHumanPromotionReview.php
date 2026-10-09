<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Separately sourced human canary decision review, not an executor.
 *
 * Positive independent scoring is *never* sufficient to publish, enroll,
 * spend, refund or promote. Even an exact current human approval is only
 * offline evidence until a distinct atomic final authorization is installed.
 */
final readonly class BoundedAutonomyOfflineHumanPromotionReview
{
    private const array SCORE_KEYS = [
        'status', 'reason_code', 'experiment_id', 'cohort_receipt_id',
        'plan_sha256', 'analysis_sha256', 'outcome_manifest_sha256',
        'execution_authorized', 'promotion_authorized', 'external_outcome_proven',
    ];

    private const array DECISION_KEYS = [
        'tenant', 'experiment_id', 'plan_sha256', 'analysis_sha256',
        'cohort_receipt_id', 'outcome_manifest_sha256', 'decision_id',
        'deciding_actor_id', 'outcome', 'policy_version',
        'authenticated_human', 'current_permission_verified',
        'observed_at_unix', 'expires_at_unix',
    ];

    public function __construct(private BoundedAutonomyCanaryHumanDecisionSource $decisions) {}

    public function inspect(
        TenantContext $actor,
        ExperimentPlan $experiment,
        ExperimentAnalysisPlan $analysis,
        array $joinedScore,
        DateTimeImmutable $at,
    ): array {
        if ($actor->actorId === '' || $experiment->workspaceId !== $actor->workspaceId
            || $experiment->brandId !== $actor->brandId
            || $experiment->weights !== $analysis->weights
            || $experiment->control !== $analysis->control
            || $experiment->holdout === null
            || $experiment->holdout !== $analysis->holdout
            || $experiment->unitKind !== $analysis->unitKind) {
            throw new InvalidArgumentException('Untrusted canary review experiment scope.');
        }

        self::keys($joinedScore, self::SCORE_KEYS);
        if ($joinedScore['experiment_id'] !== $experiment->id
            || $joinedScore['plan_sha256'] !== $experiment->fingerprint()
            || ! in_array($joinedScore['status'], ['held_offline', 'offline_joined_operator_review_candidate'], true)
            || ! is_string($joinedScore['reason_code'])
            || $joinedScore['execution_authorized'] !== false
            || $joinedScore['promotion_authorized'] !== false
            || $joinedScore['external_outcome_proven'] !== false) {
            throw new InvalidArgumentException('Untrusted canary scoring or model-side authority.');
        }

        if ($joinedScore['status'] !== 'offline_joined_operator_review_candidate') {
            return $this->held($experiment, 'independent_outcome_not_promotable');
        }

        if ($joinedScore['reason_code'] !== 'independent_human_promotion_gate_required'
            || $joinedScore['analysis_sha256'] !== $analysis->fingerprint()
            || ! self::digest($joinedScore['outcome_manifest_sha256'])
            || ! self::identifier($joinedScore['cohort_receipt_id'])) {
            throw new InvalidArgumentException('Untrusted score-to-cohort promotion binding.');
        }

        $decision = $this->decisions->latest($actor, $experiment->id, $at);
        if ($decision === null) {
            return $this->held($experiment, 'independent_human_decision_unavailable');
        }
        self::keys($decision, self::DECISION_KEYS);

        if ($decision['tenant'] !== $actor->toArray()
            || $decision['experiment_id'] !== $experiment->id
            || $decision['plan_sha256'] !== $experiment->fingerprint()
            || $decision['analysis_sha256'] !== $analysis->fingerprint()
            || $decision['cohort_receipt_id'] !== $joinedScore['cohort_receipt_id']
            || $decision['outcome_manifest_sha256'] !== $joinedScore['outcome_manifest_sha256']) {
            return $this->held($experiment, 'human_decision_evidence_drift');
        }

        if (! self::identifier($decision['decision_id'])
            || ! self::identifier($decision['deciding_actor_id'])
            || $decision['policy_version'] !== 'v1'
            || ! in_array($decision['outcome'], ['approved', 'rejected', 'revoked'], true)
            || ! is_bool($decision['authenticated_human'])
            || ! is_bool($decision['current_permission_verified'])
            || ! is_int($decision['observed_at_unix'])
            || ! is_int($decision['expires_at_unix'])
            || $decision['observed_at_unix'] > $at->getTimestamp()
            || $decision['observed_at_unix'] < $at->getTimestamp() - 300
            || $decision['expires_at_unix'] <= $at->getTimestamp()
            || $decision['expires_at_unix'] > $at->getTimestamp() + 3600) {
            throw new InvalidArgumentException('Untrusted human canary decision provenance.');
        }

        if ($decision['deciding_actor_id'] === $actor->actorId) {
            return $this->held($experiment, 'self_promotion_approval_denied');
        }
        if (! $decision['authenticated_human'] || ! $decision['current_permission_verified']) {
            return $this->held($experiment, 'human_approval_authority_unverified');
        }
        if ($decision['outcome'] !== 'approved') {
            return $this->held($experiment, $decision['outcome'] === 'revoked'
                ? 'human_decision_revoked' : 'human_decision_rejected');
        }

        return [
            'status' => 'offline_human_review_evidence_ready',
            'reason_code' => 'separate_atomic_promotion_and_provider_gates_required',
            'experiment_id' => $experiment->id,
            'plan_sha256' => $experiment->fingerprint(),
            'cohort_receipt_id' => $joinedScore['cohort_receipt_id'],
            'outcome_manifest_sha256' => $joinedScore['outcome_manifest_sha256'],
            'decision_id' => $decision['decision_id'],
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'external_outcome_proven' => false,
        ];
    }

    private function held(ExperimentPlan $experiment, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'experiment_id' => $experiment->id,
            'plan_sha256' => $experiment->fingerprint(),
            'cohort_receipt_id' => null,
            'outcome_manifest_sha256' => null,
            'decision_id' => null,
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'external_outcome_proven' => false,
        ];
    }

    private static function keys(array $source, array $keys): void
    {
        if (count($source) !== count($keys)
            || array_diff(array_keys($source), $keys) !== []
            || array_diff($keys, array_keys($source)) !== []) {
            throw new InvalidArgumentException('Unregistered canary human approval field.');
        }
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
