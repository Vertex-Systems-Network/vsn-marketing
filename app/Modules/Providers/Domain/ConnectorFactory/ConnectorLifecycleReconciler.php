<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use DateTimeImmutable;
use InvalidArgumentException;

final class ConnectorLifecycleReconciler
{
    private const FAILURE_CODES = [
        'assessment_unavailable',
        'deprecation_source_unavailable',
        'disable_persistence_failed',
        'provider_health_check_failed',
        'rollback_execution_failed',
        'state_write_failed',
    ];

    public function reconcile(
        ConnectorCompatibilityAssessment $assessment,
        ?ConnectorDeprecationObservation $deprecation,
        ?ConnectorLifecycleDecision $decision,
        DateTimeImmutable $observedAt,
        ?string $failureCode = null,
        ?string $idempotencyKey = null,
    ): ConnectorLifecycleHealth {
        if ($observedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Lifecycle reconciliation timestamps must use UTC.');
        }

        if ($assessment->assessedAt > $observedAt) {
            throw new InvalidArgumentException('Compatibility assessment cannot be newer than its reconciliation observation.');
        }

        if ($deprecation !== null && $deprecation->observedAt > $observedAt) {
            throw new InvalidArgumentException('Deprecation evidence cannot be newer than its reconciliation observation.');
        }

        if ($decision !== null && $decision->decidedAt > $observedAt) {
            throw new InvalidArgumentException('Lifecycle decision cannot be newer than its reconciliation observation.');
        }

        foreach ([$deprecation, $decision] as $evidence) {
            if ($evidence !== null && ($evidence->workspaceId !== $assessment->workspaceId
                || $evidence->providerKey !== $assessment->providerKey)) {
                throw new InvalidArgumentException('Lifecycle evidence cannot cross workspace or provider boundaries.');
            }
        }

        if ($decision !== null && $decision->compatibilityEvidenceSha256 !== $assessment->evidenceSha256) {
            throw new InvalidArgumentException('Lifecycle decision must reference the compatibility evidence being reconciled.');
        }

        if ($failureCode !== null && ! in_array($failureCode, self::FAILURE_CODES, true)) {
            throw new InvalidArgumentException('Lifecycle reconciliation failure code is not registered.');
        }

        if ($failureCode !== null && $idempotencyKey === null) {
            throw new InvalidArgumentException('Failed reconciliation requires its operation idempotency key.');
        }

        if ($idempotencyKey !== null && preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Lifecycle idempotency key must be a SHA-256 digest.');
        }

        if ($failureCode === 'rollback_execution_failed'
            && ($decision?->action !== 'rollback' || $idempotencyKey !== $decision->idempotencyKey)) {
            throw new InvalidArgumentException('Rollback failure must reconcile the matching rollback decision and idempotency key.');
        }

        if ($failureCode === 'disable_persistence_failed'
            && ($decision?->action !== 'disable' || $idempotencyKey !== $decision->idempotencyKey)) {
            throw new InvalidArgumentException('Disable persistence failure must reconcile the matching disable decision and idempotency key.');
        }

        [$status, $reason] = $this->outcome($assessment, $deprecation, $decision, $failureCode);
        $deprecationSha256 = $deprecation === null
            ? null
            : hash('sha256', json_encode($deprecation->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $identity = [
            'workspace_id' => $assessment->workspaceId,
            'provider_key' => $assessment->providerKey,
            'compatibility_evidence_sha256' => $assessment->evidenceSha256,
            'deprecation_evidence_sha256' => $deprecationSha256,
            'decision_audit_sha256' => $decision?->auditSha256,
            'failure_code' => $failureCode,
            'status' => $status,
            'reason' => $reason,
        ];
        $reconciliationKey = $idempotencyKey ?? hash('sha256', json_encode(
            $identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return new ConnectorLifecycleHealth(
            workspaceId: $assessment->workspaceId,
            providerKey: $assessment->providerKey,
            status: $status,
            reason: $reason,
            observedAt: $observedAt,
            compatibilityEvidenceSha256: $assessment->evidenceSha256,
            deprecationEvidenceSha256: $deprecationSha256,
            decisionAuditSha256: $decision?->auditSha256,
            failureCode: $failureCode,
            reconciliationKey: $reconciliationKey,
            compatibilityEvidence: $assessment->toArray(),
            deprecationEvidence: $deprecation?->toArray(),
            decisionEvidence: $decision?->toArray(),
        );
    }

    /** @return array{string, string} */
    private function outcome(
        ConnectorCompatibilityAssessment $assessment,
        ?ConnectorDeprecationObservation $deprecation,
        ?ConnectorLifecycleDecision $decision,
        ?string $failureCode,
    ): array {
        if ($failureCode !== null) {
            return [$assessment->status === 'compatible' ? 'degraded' : 'blocked', 'reconciliation_failed'];
        }

        if ($decision?->action === 'disable') {
            return ['disabled', 'operator_disabled'];
        }

        if ($decision?->action === 'rollback') {
            return ['rollback_pending', 'operator_rollback_requires_execution_evidence'];
        }

        if ($deprecation?->sunsetDue() === true) {
            return ['blocked', 'contract_sunset_due'];
        }

        if ($assessment->status !== 'compatible') {
            return ['blocked', 'compatibility_'.$assessment->status.':'.$assessment->reason];
        }

        if ($deprecation?->alertRequired() === true) {
            return ['degraded', 'contract_deprecated'];
        }

        return ['healthy', 'contract_compatible'];
    }
}
