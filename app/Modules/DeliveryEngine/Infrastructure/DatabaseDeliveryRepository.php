<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryMessage;
use App\Modules\DeliveryEngine\Domain\MessageExecutionSnapshot;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\RecipientExecutionSnapshot;
use App\Modules\DeliveryEngine\Domain\RecipientIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use stdClass;

final readonly class DatabaseDeliveryRepository implements DeliveryRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function createMessage(
        string $id,
        string $workspaceId,
        ?string $brandId,
        string $businessIntentKey,
        MessageIntentType $intentType,
        DeliveryChannel $channel,
        array $content,
        array $metadata,
    ): DeliveryMessage {
        $this->assertBrandInWorkspace($workspaceId, $brandId);

        try {
            $this->database->connection()->table('delivery_messages')->insert([
                'id' => $id,
                'workspace_id' => $workspaceId,
                'brand_id' => $brandId,
                'business_intent_key' => $businessIntentKey,
                'intent_type' => $intentType->value,
                'channel' => $channel->value,
                'content' => json_encode($content, JSON_THROW_ON_ERROR),
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $this->clock->now(),
            ]);
        } catch (QueryException $exception) {
            throw new InvalidArgumentException('Business intent key already exists in this workspace.', previous: $exception);
        }

        return new DeliveryMessage($id, $workspaceId, $brandId, $businessIntentKey, $intentType, $channel, $content, $metadata);
    }

    public function findMessage(string $workspaceId, ?string $brandScopeId, string $messageId): ?DeliveryMessage
    {
        $query = $this->database->connection()->table('delivery_messages')
            ->where('workspace_id', $workspaceId)
            ->where('id', $messageId);

        if ($brandScopeId !== null) {
            $query->where('brand_id', $brandScopeId);
        }

        $row = $query->first();
        if (! $row instanceof stdClass) {
            return null;
        }

        return new DeliveryMessage(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            brandId: $row->brand_id === null ? null : (string) $row->brand_id,
            businessIntentKey: (string) $row->business_intent_key,
            intentType: MessageIntentType::from((string) $row->intent_type),
            channel: DeliveryChannel::from((string) $row->channel),
            content: (array) json_decode((string) $row->content, true, 512, JSON_THROW_ON_ERROR),
            metadata: (array) json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function createMessageSnapshot(string $id, DeliveryMessage $message, string $contentHash): MessageExecutionSnapshot
    {
        $connection = $this->database->connection();
        $locked = $connection->table('delivery_messages')
            ->where('id', $message->id)
            ->where('workspace_id', $message->workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof stdClass) {
            throw new AuthorizationException('Delivery message access denied.');
        }

        $version = ((int) $connection->table('delivery_message_snapshots')
            ->where('message_id', $message->id)
            ->max('version')) + 1;

        $connection->table('delivery_message_snapshots')->insert([
            'id' => $id,
            'workspace_id' => $message->workspaceId,
            'message_id' => $message->id,
            'version' => $version,
            'business_intent_key' => $message->businessIntentKey,
            'intent_type' => $message->intentType->value,
            'channel' => $message->channel->value,
            'content' => json_encode($message->content, JSON_THROW_ON_ERROR),
            'metadata' => json_encode($message->metadata, JSON_THROW_ON_ERROR),
            'content_hash' => $contentHash,
            'created_at' => $this->clock->now(),
        ]);

        return new MessageExecutionSnapshot(
            id: $id,
            workspaceId: $message->workspaceId,
            messageId: $message->id,
            version: $version,
            businessIntentKey: $message->businessIntentKey,
            intentType: $message->intentType,
            channel: $message->channel,
            content: $message->content,
            metadata: $message->metadata,
            contentHash: $contentHash,
        );
    }

    public function createRecipientSnapshot(
        string $id,
        MessageExecutionSnapshot $messageSnapshot,
        RecipientIdentity $recipient,
        string $contentHash,
    ): RecipientExecutionSnapshot {
        $this->database->connection()->table('delivery_recipient_snapshots')->insert([
            'id' => $id,
            'workspace_id' => $messageSnapshot->workspaceId,
            'message_snapshot_id' => $messageSnapshot->id,
            'contact_id' => $recipient->contactId,
            'contact_identity_id' => $recipient->contactIdentityId,
            'channel' => $messageSnapshot->channel->value,
            'destination' => $recipient->value,
            'normalized_destination' => $recipient->normalizedValue,
            'identity_provider' => $recipient->provider,
            'identity_provider_reference' => $recipient->providerReference,
            'identity_verified_at' => $recipient->verifiedAt,
            'content_hash' => $contentHash,
            'created_at' => $this->clock->now(),
        ]);

        return new RecipientExecutionSnapshot(
            id: $id,
            workspaceId: $messageSnapshot->workspaceId,
            messageSnapshotId: $messageSnapshot->id,
            contactId: $recipient->contactId,
            contactIdentityId: $recipient->contactIdentityId,
            channel: $messageSnapshot->channel,
            destination: $recipient->value,
            normalizedDestination: $recipient->normalizedValue,
            identityProvider: $recipient->provider,
            identityProviderReference: $recipient->providerReference,
            identityVerifiedAt: $recipient->verifiedAt,
            contentHash: $contentHash,
        );
    }

    private function assertBrandInWorkspace(string $workspaceId, ?string $brandId): void
    {
        if ($brandId === null) {
            return;
        }

        $exists = $this->database->connection()->table('brands')
            ->where('id', $brandId)
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $exists) {
            throw new AuthorizationException('Brand access denied.');
        }
    }
}
