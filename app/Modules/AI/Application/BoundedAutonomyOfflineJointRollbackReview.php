<?php

namespace App\Modules\AI\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Dual-source read-only rollback review. A positive aggregate provider
 * assertion alone must never establish a completed rollback or verified
 * non-effect: the source also needs individually bound attempt readback.
 *
 * No refunds, retries, provider mutations, spending or campaign promotion.
 */
final readonly class BoundedAutonomyOfflineJointRollbackReview
{
    public function __construct(
        private BoundedAutonomyOfflineRollbackReview $aggregate,
        private BoundedAutonomyOfflineProviderAttemptReview $attempts,
    ) {}

    public function inspect(
        TenantContext $actor,
        string $runId,
        string $snapshotSha256,
        DateTimeImmutable $at,
    ): array {
        $summary = $this->aggregate->inspect($actor, $runId, $snapshotSha256, $at);
        $detail = $this->attempts->inspect($actor, $runId, $snapshotSha256, $at);

        if (($summary['run_id'] ?? null) !== $runId
            || ($summary['snapshot_sha256'] ?? null) !== $snapshotSha256
            || ($detail['run_id'] ?? null) !== $runId
            || ($detail['snapshot_sha256'] ?? null) !== $snapshotSha256
            || ! in_array($summary['status'] ?? null, ['held_offline', 'offline_rollback_review_ready'], true)
            || ! in_array($detail['status'] ?? null, ['held_offline', 'offline_no_effect_reconciliation_candidate'], true)) {
            throw new InvalidArgumentException('Untrusted provider rollback evidence join.');
        }

        foreach (['rollback_performed', 'refund_authorized', 'retry_authorized', 'execution_authorized', 'promotion_authorized'] as $field) {
            if (($summary[$field] ?? null) !== false || ($detail[$field] ?? null) !== false) {
                throw new InvalidArgumentException('Provider evidence attempted effect authorization.');
            }
        }

        if ($summary['status'] !== 'offline_rollback_review_ready'
            || ($summary['external_outcome_verified'] ?? null) !== true) {
            return $this->held($runId, $snapshotSha256, 'aggregate_provider_outcome_unresolved');
        }
        if ($detail['status'] !== 'offline_no_effect_reconciliation_candidate'
            || ! is_int($detail['verified_attempt_count'] ?? null)
            || $detail['verified_attempt_count'] < 1
            || ! is_string($detail['source_manifest_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $detail['source_manifest_sha256']) !== 1) {
            return $this->held($runId, $snapshotSha256, 'individual_provider_attempts_unresolved');
        }

        return [
            'status' => 'offline_joint_rollback_review_ready',
            'reason_code' => 'independent_human_recovery_gate_required',
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'source_manifest_sha256' => $detail['source_manifest_sha256'],
            'verified_attempt_count' => $detail['verified_attempt_count'],
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private function held(string $runId, string $snapshotSha256, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'source_manifest_sha256' => null,
            'verified_attempt_count' => 0,
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
