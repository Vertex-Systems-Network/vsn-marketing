<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\DeliveryEngine\Domain\CanonicalSnapshotHasher;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\Contracts\RecipientSource;
use App\Modules\DeliveryEngine\Domain\ExecutionSnapshots;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class MaterializeExecutionSnapshots
{
    public const MESSAGE_AUDIT_ACTION = 'delivery.message_snapshot.materialized';
    public const RECIPIENT_AUDIT_ACTION = 'delivery.recipient_snapshot.materialized';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private DeliveryRepository $repository,
        private RecipientSource $recipients,
        private DeliveryTransaction $transaction,
        private CanonicalSnapshotHasher $hasher,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $messageId,
        string $contactId,
        string $contactIdentityId,
    ): ExecutionSnapshots {
        return $this->transaction->run(function () use ($context, $messageId, $contactId, $contactIdentityId): ExecutionSnapshots {
            $message = $this->repository->findMessage($context->workspaceId, $context->brandId, $messageId);
            if ($message === null) {
                throw new AuthorizationException('Delivery message access denied.');
            }

            $recipient = $this->recipients->resolve($context, $contactId, $contactIdentityId, $message->channel);
            $messageHash = $this->hasher->hash([
                'workspace_id' => $message->workspaceId,
                'message_id' => $message->id,
                'business_intent_key' => $message->businessIntentKey,
                'intent_type' => $message->intentType->value,
                'channel' => $message->channel->value,
                'content' => $message->content,
                'metadata' => $message->metadata,
            ]);

            $messageSnapshot = $this->repository->createMessageSnapshot(
                $this->identifiers->next(),
                $message,
                $messageHash,
            );

            $recipientHash = $this->hasher->hash([
                'workspace_id' => $message->workspaceId,
                'message_snapshot_id' => $messageSnapshot->id,
                'contact_id' => $recipient->contactId,
                'contact_identity_id' => $recipient->contactIdentityId,
                'channel' => $message->channel->value,
                'destination' => $recipient->value,
                'normalized_destination' => $recipient->normalizedValue,
                'identity_provider' => $recipient->provider,
                'identity_provider_reference' => $recipient->providerReference,
                'identity_verified_at' => $recipient->verifiedAt,
            ]);

            $recipientSnapshot = $this->repository->createRecipientSnapshot(
                $this->identifiers->next(),
                $messageSnapshot,
                $recipient,
                $recipientHash,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::MESSAGE_AUDIT_ACTION,
                subjectType: 'delivery_message_snapshot',
                subjectId: $messageSnapshot->id,
                evidence: [
                    'message_id' => $message->id,
                    'version' => $messageSnapshot->version,
                    'content_hash' => $messageSnapshot->contentHash,
                ],
            );
            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::RECIPIENT_AUDIT_ACTION,
                subjectType: 'delivery_recipient_snapshot',
                subjectId: $recipientSnapshot->id,
                evidence: [
                    'message_snapshot_id' => $messageSnapshot->id,
                    'contact_id' => $recipient->contactId,
                    'contact_identity_id' => $recipient->contactIdentityId,
                    'content_hash' => $recipientSnapshot->contentHash,
                ],
            );

            return new ExecutionSnapshots($messageSnapshot, $recipientSnapshot);
        });
    }
}
