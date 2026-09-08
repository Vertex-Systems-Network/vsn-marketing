<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryAdmissionResult;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AdmitDeliveryOperation
{
    public const ADMITTED_AUDIT_ACTION = 'delivery.operation.admitted';

    public const BACKPRESSURED_AUDIT_ACTION = 'delivery.operation.backpressured';

    public function __construct(
        private DeliveryAdmissionRepository $repository,
        private DeliveryTransaction $transaction,
        private AuditRecorder $audit,
    ) {}

    public function handle(TenantContext $context, DeliveryOperation $operation): DeliveryAdmissionResult
    {
        if ($operation->workspaceId !== $context->workspaceId) {
            throw new AuthorizationException('Delivery operation access denied.');
        }

        return $this->transaction->run(function () use ($context, $operation): DeliveryAdmissionResult {
            $result = $this->repository->admit(
                operation: $operation,
                providerOperation: $operation->channel->providerOperation(),
            );

            if (! $result->changed) {
                return $result;
            }

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: $result->admitted
                    ? self::ADMITTED_AUDIT_ACTION
                    : self::BACKPRESSURED_AUDIT_ACTION,
                subjectType: 'delivery_operation',
                subjectId: $result->operation->id,
                evidence: [
                    'state' => $result->operation->state->value,
                    'provider_id' => $result->providerId,
                    'provider_connection_id' => $result->providerConnectionId,
                    'backpressure_reason' => $result->backpressureReason,
                    'version' => $result->operation->version,
                ],
            );

            return $result;
        });
    }
}
