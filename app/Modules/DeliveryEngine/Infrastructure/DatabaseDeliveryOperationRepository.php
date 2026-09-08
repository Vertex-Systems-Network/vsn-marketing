<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryOperationRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationCreation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\DeliveryQueueRoute;
use App\Modules\DeliveryEngine\Domain\ExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\MessageExecutionSnapshot;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\RecipientExecutionSnapshot;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use stdClass;

final readonly class DatabaseDeliveryOperationRepository implements DeliveryOperationRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function findExecutionSnapshots(
        string $workspaceId,
        ?string $brandScopeId,
        string $messageSnapshotId,
        string $recipientSnapshotId,
    ): ?ExecutionSnapshots {
        $messageQuery = $this->database->connection()
            ->table('delivery_message_snapshots as snapshots')
            ->join('delivery_messages as messages', function ($join): void {
                $join->on('messages.id', '=', 'snapshots.message_id')
                    ->on('messages.workspace_id', '=', 'snapshots.workspace_id');
            })
            ->where('snapshots.workspace_id', $workspaceId)
            ->where('snapshots.id', $messageSnapshotId)
            ->select('snapshots.*');

        if ($brandScopeId !== null) {
            $messageQuery->where('messages.brand_id', $brandScopeId);
        }

        $messageRow = $messageQuery->first();
        if (! $messageRow instanceof stdClass) {
            return null;
        }

        $recipientRow = $this->database->connection()
            ->table('delivery_recipient_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('id', $recipientSnapshotId)
            ->where('message_snapshot_id', $messageSnapshotId)
            ->first();

        if (! $recipientRow instanceof stdClass) {
            return null;
        }

        return new ExecutionSnapshots(
            message: new MessageExecutionSnapshot(
                id: (string) $messageRow->id,
                workspaceId: (string) $messageRow->workspace_id,
                messageId: (string) $messageRow->message_id,
                version: (int) $messageRow->version,
                businessIntentKey: (string) $messageRow->business_intent_key,
                intentType: MessageIntentType::from((string) $messageRow->intent_type),
                channel: DeliveryChannel::from((string) $messageRow->channel),
                content: (array) json_decode((string) $messageRow->content, true, 512, JSON_THROW_ON_ERROR),
                metadata: (array) json_decode((string) $messageRow->metadata, true, 512, JSON_THROW_ON_ERROR),
                contentHash: (string) $messageRow->content_hash,
            ),
            recipient: new RecipientExecutionSnapshot(
                id: (string) $recipientRow->id,
                workspaceId: (string) $recipientRow->workspace_id,
                messageSnapshotId: (string) $recipientRow->message_snapshot_id,
                contactId: (string) $recipientRow->contact_id,
                contactIdentityId: (string) $recipientRow->contact_identity_id,
                channel: DeliveryChannel::from((string) $recipientRow->channel),
                destination: (string) $recipientRow->destination,
                normalizedDestination: (string) $recipientRow->normalized_destination,
                identityProvider: $recipientRow->identity_provider === null ? null : (string) $recipientRow->identity_provider,
                identityProviderReference: $recipientRow->identity_provider_reference === null
                    ? null
                    : (string) $recipientRow->identity_provider_reference,
                identityVerifiedAt: $recipientRow->identity_verified_at === null
                    ? null
                    : (string) $recipientRow->identity_verified_at,
                contentHash: (string) $recipientRow->content_hash,
            ),
        );
    }

    public function createOrFind(
        string $id,
        ExecutionSnapshots $snapshots,
        string $idempotencyKey,
        DateTimeImmutable $scheduledNotBeforeAt,
        DeliveryPriorityClass $priorityClass,
        DeliveryQueueRoute $route,
    ): DeliveryOperationCreation {
        $now = $this->clock->now();
        $state = $scheduledNotBeforeAt > $now
            ? DeliveryOperationState::Scheduled
            : DeliveryOperationState::Ready;

        try {
            $this->database->connection()->table('delivery_operations')->insert([
                'id' => $id,
                'workspace_id' => $snapshots->message->workspaceId,
                'message_snapshot_id' => $snapshots->message->id,
                'recipient_snapshot_id' => $snapshots->recipient->id,
                'channel' => $snapshots->message->channel->value,
                'idempotency_key' => $idempotencyKey,
                'scheduled_not_before_at' => $scheduledNotBeforeAt,
                'priority_class' => $priorityClass->value,
                'state' => $state->value,
                'queue_name' => $route->queueName,
                'queue_partition_key' => $route->partitionKey,
                'backpressure_reason' => null,
                'backpressured_at' => null,
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            $existing = $this->findByIdempotencyKey($snapshots->message->workspaceId, $idempotencyKey);
            if ($existing !== null) {
                return new DeliveryOperationCreation($existing, false);
            }

            throw $exception;
        }

        $created = $this->findByIdempotencyKey($snapshots->message->workspaceId, $idempotencyKey);
        if ($created === null) {
            throw new QueryException(
                $this->database->connection()->getName(),
                'select delivery operation after insert',
                [],
                new \RuntimeException('Inserted delivery operation could not be read back.'),
            );
        }

        return new DeliveryOperationCreation($created, true);
    }

    private function findByIdempotencyKey(string $workspaceId, string $idempotencyKey): ?DeliveryOperation
    {
        $row = $this->database->connection()->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return $this->toOperation($row);
    }

    private function toOperation(stdClass $row): DeliveryOperation
    {
        return new DeliveryOperation(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            messageSnapshotId: (string) $row->message_snapshot_id,
            recipientSnapshotId: (string) $row->recipient_snapshot_id,
            channel: DeliveryChannel::from((string) $row->channel),
            idempotencyKey: (string) $row->idempotency_key,
            scheduledNotBeforeAt: new DateTimeImmutable((string) $row->scheduled_not_before_at),
            priorityClass: DeliveryPriorityClass::from((string) $row->priority_class),
            state: DeliveryOperationState::from((string) $row->state),
            queueName: (string) $row->queue_name,
            queuePartitionKey: (string) $row->queue_partition_key,
            backpressureReason: $row->backpressure_reason === null ? null : (string) $row->backpressure_reason,
            backpressuredAt: $row->backpressured_at === null ? null : new DateTimeImmutable((string) $row->backpressured_at),
            version: (int) $row->version,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
