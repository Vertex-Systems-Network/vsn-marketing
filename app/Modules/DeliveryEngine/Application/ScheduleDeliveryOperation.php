<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryOperationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationScheduleResult;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

final readonly class ScheduleDeliveryOperation
{
    public const CREATED_AUDIT_ACTION = 'delivery.operation.scheduled';

    public const REUSED_AUDIT_ACTION = 'delivery.operation.idempotent_reused';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private DeliveryOperationRepository $operations,
        private DeliveryTransaction $transaction,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $messageSnapshotId,
        string $recipientSnapshotId,
        string $providerConnectionId,
        DateTimeImmutable $scheduledNotBeforeAt,
    ): DeliveryOperationScheduleResult {
        return $this->transaction->run(function () use (
            $context,
            $messageSnapshotId,
            $recipientSnapshotId,
            $providerConnectionId,
            $scheduledNotBeforeAt,
        ): DeliveryOperationScheduleResult {
            $result = $this->operations->schedule(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                messageSnapshotId: $messageSnapshotId,
                recipientSnapshotId: $recipientSnapshotId,
                providerConnectionId: $providerConnectionId,
                scheduledNotBeforeAt: $scheduledNotBeforeAt,
            );

            $operation = $result->operation;
            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: $result->created ? self::CREATED_AUDIT_ACTION : self::REUSED_AUDIT_ACTION,
                subjectType: 'delivery_operation',
                subjectId: $operation->id,
                evidence: [
                    'message_snapshot_id' => $operation->messageSnapshotId,
                    'recipient_snapshot_id' => $operation->recipientSnapshotId,
                    'provider_connection_id' => $operation->providerConnectionId,
                    'channel' => $operation->channel->value,
                    'idempotency_key' => $operation->idempotencyKey,
                    'scheduled_not_before_at' => $operation->scheduledNotBeforeAt->format(DATE_ATOM),
                    'priority_class' => $operation->priorityClass->value,
                    'state' => $operation->state->value,
                    'created' => $result->created,
                ],
            );

            return $result;
        });
    }
}
