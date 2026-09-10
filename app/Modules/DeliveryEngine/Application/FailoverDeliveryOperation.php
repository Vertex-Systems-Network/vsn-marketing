<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryReconciliationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryAdmissionResult;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class FailoverDeliveryOperation
{
    public const AUDIT_ACTION = 'delivery.operation.failover_prepared';

    public function __construct(
        private Clock $clock,
        private DeliveryReconciliationRepository $repository,
        private DeliveryTransaction $transaction,
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
        $operation = $this->transaction->run(function () use (
            $context,
            $operationId,
            $reconciliationAttemptId,
            $alternateProviderId,
            $alternateProviderConnectionId,
            $preparedAt,
        ) {
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
                ],
            );

            return $prepared;
        });

        return $this->admit->handle($context, $operation);
    }
}
