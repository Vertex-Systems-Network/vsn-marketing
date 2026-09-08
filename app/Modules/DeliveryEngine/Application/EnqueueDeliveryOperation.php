<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryOperationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationKey;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\DeliveryQueueRoute;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class EnqueueDeliveryOperation
{
    public const AUDIT_ACTION = 'delivery.operation.enqueued';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private Clock $clock,
        private DeliveryOperationRepository $repository,
        private DeliveryTransaction $transaction,
        private DeliveryOperationKey $keys,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $messageSnapshotId,
        string $recipientSnapshotId,
        ?DateTimeImmutable $scheduledNotBeforeAt = null,
        DeliveryPriorityClass $priorityClass = DeliveryPriorityClass::Normal,
    ): DeliveryOperation {
        return $this->transaction->run(function () use (
            $context,
            $messageSnapshotId,
            $recipientSnapshotId,
            $scheduledNotBeforeAt,
            $priorityClass,
        ): DeliveryOperation {
            $snapshots = $this->repository->findExecutionSnapshots(
                workspaceId: $context->workspaceId,
                brandScopeId: $context->brandId,
                messageSnapshotId: $messageSnapshotId,
                recipientSnapshotId: $recipientSnapshotId,
            );

            if ($snapshots === null) {
                throw new AuthorizationException('Delivery execution snapshot access denied.');
            }

            $idempotencyKey = $this->keys->derive($snapshots->message, $snapshots->recipient);
            $route = DeliveryQueueRoute::for(
                workspaceId: $context->workspaceId,
                channel: $snapshots->message->channel,
                priority: $priorityClass,
            );
            $scheduledAt = $scheduledNotBeforeAt ?? $this->clock->now();

            $creation = $this->repository->createOrFind(
                id: $this->identifiers->next(),
                snapshots: $snapshots,
                idempotencyKey: $idempotencyKey,
                scheduledNotBeforeAt: $scheduledAt,
                priorityClass: $priorityClass,
                route: $route,
            );

            if ($creation->created) {
                $this->audit->record(
                    workspaceId: $context->workspaceId,
                    brandId: $context->brandId,
                    actorId: $context->actorId,
                    action: self::AUDIT_ACTION,
                    subjectType: 'delivery_operation',
                    subjectId: $creation->operation->id,
                    evidence: [
                        'message_snapshot_id' => $snapshots->message->id,
                        'recipient_snapshot_id' => $snapshots->recipient->id,
                        'channel' => $snapshots->message->channel->value,
                        'idempotency_key' => $idempotencyKey,
                        'priority_class' => $priorityClass->value,
                        'queue_name' => $route->queueName,
                        'queue_partition_key' => $route->partitionKey,
                        'scheduled_not_before_at' => $scheduledAt->format(DATE_ATOM),
                    ],
                );
            }

            return $creation->operation;
        });
    }
}
