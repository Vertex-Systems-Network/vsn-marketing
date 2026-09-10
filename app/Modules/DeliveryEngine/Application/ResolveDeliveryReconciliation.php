<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryReconciliationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResult;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ResolveDeliveryReconciliation
{
    public const AUDIT_ACTION = 'delivery.operation.reconciliation_recorded';

    public function __construct(
        private Clock $clock,
        private DeliveryReconciliationRepository $repository,
        private DeliveryTransaction $transaction,
        private DeliveryReconciliationPolicy $policy,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $operationId,
        string $attemptId,
        DeliveryReconciliationEvidence $evidence,
    ): DeliveryReconciliationResult {
        $observedAt = $this->clock->now();

        return $this->transaction->run(function () use (
            $context,
            $operationId,
            $attemptId,
            $evidence,
            $observedAt,
        ): DeliveryReconciliationResult {
            $snapshot = $this->repository->lockSnapshot(
                workspaceId: $context->workspaceId,
                operationId: $operationId,
                attemptId: $attemptId,
            );

            if ($snapshot === null || $snapshot->operation->workspaceId !== $context->workspaceId) {
                throw new AuthorizationException('Delivery reconciliation access denied or unavailable.');
            }

            $canonicalEvidence = new DeliveryReconciliationEvidence(
                providerAccepted: $evidence->providerAccepted,
                acceptanceKnownNotOccurred: $evidence->acceptanceKnownNotOccurred,
                retrySafe: $evidence->retrySafe,
                probeAttemptNumber: $evidence->probeAttemptNumber,
                maxProbeAttempts: $snapshot->maxProbeAttempts,
                reason: $evidence->reason,
            );
            $decision = $this->policy->decide($canonicalEvidence);
            $result = $this->repository->resolve(
                $snapshot,
                $canonicalEvidence,
                $decision,
                $observedAt,
            );
            if (! $result->changed) {
                return $result;
            }

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'delivery_operation',
                subjectId: $result->operation->id,
                evidence: [
                    'attempt_id' => $result->attemptId,
                    'probe_attempt_number' => $canonicalEvidence->probeAttemptNumber,
                    'resolution' => $result->resolution->value,
                    'provider_accepted' => $canonicalEvidence->providerAccepted,
                    'acceptance_known_not_occurred' => $canonicalEvidence->acceptanceKnownNotOccurred,
                    'retry_safe' => $canonicalEvidence->retrySafe,
                    'retry_allowed' => $result->retryAllowed,
                    'operator_action_required' => $result->operatorActionRequired,
                    'operation_state' => $result->operation->state->value,
                    'evidence_reason' => $result->reason,
                    'policy_reason' => $decision->reason,
                ],
            );

            return $result;
        });
    }
}
