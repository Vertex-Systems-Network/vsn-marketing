<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryFailoverEligibilityRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryReconciliationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryAdmissionResult;
use App\Modules\DeliveryEngine\Domain\DeliveryFailoverPolicy;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final readonly class FailoverDeliveryOperation
{
    public const AUDIT_ACTION = 'delivery.operation.failover_prepared';

    public function __construct(
        private Clock $clock,
        private DeliveryReconciliationRepository $repository,
        private DeliveryFailoverEligibilityRepository $eligibilityRepository,
        private DeliveryTransaction $transaction,
        private DeliveryFailoverPolicy $policy,
        private AdmitDeliveryOperation $admit,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $operationId,
        string $reconciliationAttemptId,
        string $alternateProviderId,
        string $alternateProviderConnectionId,
    ): DeliveryAdmissionResult {
        $preparedAt = $this->clock->now();

        return $this->transaction->run(function () use (
            $context,
            $operationId,
            $reconciliationAttemptId,
            $alternateProviderId,
            $alternateProviderConnectionId,
            $preparedAt,
        ): DeliveryAdmissionResult {
            $snapshot = $this->repository->lockSnapshot(
                workspaceId: $context->workspaceId,
                operationId: $operationId,
                attemptId: $reconciliationAttemptId,
            );

            if ($snapshot === null || $snapshot->operation->workspaceId !== $context->workspaceId) {
                throw new AuthorizationException('Delivery failover access denied or unavailable.');
            }

            $eligibility = $this->eligibilityRepository->assess(
                $snapshot,
                $alternateProviderId,
                $alternateProviderConnectionId,
                $preparedAt,
            );
            $decision = $this->policy->decide(
                previousRouteAcceptance: $eligibility->previousRouteAcceptance,
                sameWorkspace: $eligibility->sameWorkspace,
                tenantChecksPass: $eligibility->tenantChecksPass,
                capabilityCompatible: $eligibility->capabilityCompatible,
                policyAllows: $eligibility->policyAllows,
                connectionReady: $eligibility->connectionReady,
                quotaAvailable: $eligibility->quotaAvailable,
                breakerAllows: $eligibility->breakerAllows,
            );

            if (! $decision->eligible) {
                throw new RuntimeException('Delivery failover denied by policy: '.$decision->reason.'.');
            }

            $prepared = $this->repository->prepareFailover(
                workspaceId: $context->workspaceId,
                operationId: $operationId,
                attemptId: $reconciliationAttemptId,
                alternateProviderId: $alternateProviderId,
                alternateProviderConnectionId: $alternateProviderConnectionId,
                preparedAt: $preparedAt,
            );

            if ($prepared === null || $prepared->workspaceId !== $context->workspaceId) {
                throw new AuthorizationException('Delivery failover access denied or unavailable.');
            }

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'delivery_operation',
                subjectId: $prepared->id,
                evidence: [
                    'reconciliation_attempt_id' => $reconciliationAttemptId,
                    'alternate_provider_id' => $alternateProviderId,
                    'alternate_provider_connection_id' => $alternateProviderConnectionId,
                    'operation_state' => $prepared->state->value,
                    'version' => $prepared->version,
                    'policy_reason' => $decision->reason,
                ],
            );

            return $this->admit->handle($context, $prepared);
        });
    }
}
