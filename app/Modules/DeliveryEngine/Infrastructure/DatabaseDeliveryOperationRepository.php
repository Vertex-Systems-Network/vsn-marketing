<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryOperationRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationScheduleResult;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;
use stdClass;

final readonly class DatabaseDeliveryOperationRepository implements DeliveryOperationRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function schedule(
        string $id,
        string $workspaceId,
        string $messageSnapshotId,
        string $recipientSnapshotId,
        string $providerConnectionId,
        DateTimeImmutable $scheduledNotBeforeAt,
    ): DeliveryOperationScheduleResult {
        $connection = $this->database->connection();

        $message = $connection->table('delivery_message_snapshots')
            ->where('id', $messageSnapshotId)
            ->where('workspace_id', $workspaceId)
            ->first();
        $recipient = $connection->table('delivery_recipient_snapshots')
            ->where('id', $recipientSnapshotId)
            ->where('workspace_id', $workspaceId)
            ->first();
        $providerConnection = $connection->table('provider_connections')
            ->where('id', $providerConnectionId)
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $message instanceof stdClass || ! $recipient instanceof stdClass || ! $providerConnection instanceof stdClass) {
            throw new AuthorizationException('Delivery operation admission denied.');
        }

        if (
            (string) $recipient->message_snapshot_id !== $messageSnapshotId ||
            (string) $recipient->channel !== (string) $message->channel
        ) {
            throw new LogicException('Recipient execution snapshot does not belong to the message execution snapshot.');
        }

        $channel = DeliveryChannel::from((string) $message->channel);
        $intent = MessageIntentType::from((string) $message->intent_type);
        $priority = DeliveryPriorityClass::fromIntent($intent);
        $idempotencyKey = $this->idempotencyKey(
            workspaceId: $workspaceId,
            businessIntentKey: (string) $message->business_intent_key,
            contactIdentityId: (string) $recipient->contact_identity_id,
            normalizedDestination: (string) $recipient->normalized_destination,
            channel: $channel,
        );

        $existing = $this->findByIdempotencyKey($workspaceId, $idempotencyKey);
        if ($existing !== null) {
            $this->assertIdempotentReplay(
                $existing,
                $messageSnapshotId,
                $recipientSnapshotId,
                $providerConnectionId,
                $channel,
                $scheduledNotBeforeAt,
            );

            return new DeliveryOperationScheduleResult($existing, false);
        }

        $now = $this->clock->now();
        $state = $scheduledNotBeforeAt <= $now
            ? DeliveryOperationState::Ready
            : DeliveryOperationState::Scheduled;

        try {
            $connection->table('delivery_operations')->insert([
                'id' => $id,
                'workspace_id' => $workspaceId,
                'message_snapshot_id' => $messageSnapshotId,
                'recipient_snapshot_id' => $recipientSnapshotId,
                'provider_connection_id' => $providerConnectionId,
                'channel' => $channel->value,
                'idempotency_key' => $idempotencyKey,
                'scheduled_not_before_at' => $scheduledNotBeforeAt,
                'priority_class' => $priority->value,
                'state' => $state->value,
                'version' => 1,
                'backpressure_reason' => null,
                'backpressured_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            $raced = $this->findByIdempotencyKey($workspaceId, $idempotencyKey);
            if ($raced === null) {
                throw $exception;
            }

            $this->assertIdempotentReplay(
                $raced,
                $messageSnapshotId,
                $recipientSnapshotId,
                $providerConnectionId,
                $channel,
                $scheduledNotBeforeAt,
            );

            return new DeliveryOperationScheduleResult($raced, false);
        }

        return new DeliveryOperationScheduleResult(
            new DeliveryOperation(
                id: $id,
                workspaceId: $workspaceId,
                messageSnapshotId: $messageSnapshotId,
                recipientSnapshotId: $recipientSnapshotId,
                providerConnectionId: $providerConnectionId,
                channel: $channel,
                idempotencyKey: $idempotencyKey,
                scheduledNotBeforeAt: $scheduledNotBeforeAt,
                priorityClass: $priority,
                state: $state,
                version: 1,
            ),
            true,
        );
    }

    public function findByIdempotencyKey(string $workspaceId, string $idempotencyKey): ?DeliveryOperation
    {
        $row = $this->database->connection()->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function idempotencyKey(
        string $workspaceId,
        string $businessIntentKey,
        string $contactIdentityId,
        string $normalizedDestination,
        DeliveryChannel $channel,
    ): string {
        return hash('sha256', implode("\0", [
            $workspaceId,
            $businessIntentKey,
            $contactIdentityId,
            $normalizedDestination,
            $channel->value,
        ]));
    }

    private function assertIdempotentReplay(
        DeliveryOperation $existing,
        string $messageSnapshotId,
        string $recipientSnapshotId,
        string $providerConnectionId,
        DeliveryChannel $channel,
        DateTimeImmutable $scheduledNotBeforeAt,
    ): void {
        if (
            $existing->messageSnapshotId !== $messageSnapshotId ||
            $existing->recipientSnapshotId !== $recipientSnapshotId ||
            $existing->providerConnectionId !== $providerConnectionId ||
            $existing->channel !== $channel ||
            $existing->scheduledNotBeforeAt->getTimestamp() !== $scheduledNotBeforeAt->getTimestamp()
        ) {
            throw new LogicException('Delivery operation idempotency key conflicts with a different immutable execution intent.');
        }
    }

    private function hydrate(stdClass $row): DeliveryOperation
    {
        return new DeliveryOperation(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            messageSnapshotId: (string) $row->message_snapshot_id,
            recipientSnapshotId: (string) $row->recipient_snapshot_id,
            providerConnectionId: (string) $row->provider_connection_id,
            channel: DeliveryChannel::from((string) $row->channel),
            idempotencyKey: (string) $row->idempotency_key,
            scheduledNotBeforeAt: new DateTimeImmutable((string) $row->scheduled_not_before_at),
            priorityClass: DeliveryPriorityClass::from((string) $row->priority_class),
            state: DeliveryOperationState::from((string) $row->state),
            version: (int) $row->version,
            backpressureReason: $row->backpressure_reason === null ? null : (string) $row->backpressure_reason,
            backpressuredAt: $row->backpressured_at === null ? null : new DateTimeImmutable((string) $row->backpressured_at),
        );
    }
}
