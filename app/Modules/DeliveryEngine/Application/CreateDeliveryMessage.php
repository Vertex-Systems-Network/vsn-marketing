<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryMessage;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class CreateDeliveryMessage
{
    public const AUDIT_ACTION = 'delivery.message.created';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private DeliveryRepository $repository,
        private DeliveryTransaction $transaction,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $businessIntentKey,
        MessageIntentType $intentType,
        DeliveryChannel $channel,
        array $content,
        array $metadata = [],
    ): DeliveryMessage {
        $businessIntentKey = trim($businessIntentKey);
        if ($businessIntentKey === '' || strlen($businessIntentKey) > 191) {
            throw new InvalidArgumentException('Business intent key must be between 1 and 191 characters.');
        }

        if ($content === []) {
            throw new InvalidArgumentException('Canonical message content cannot be empty.');
        }

        return $this->transaction->run(function () use ($context, $businessIntentKey, $intentType, $channel, $content, $metadata): DeliveryMessage {
            $message = $this->repository->createMessage(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                businessIntentKey: $businessIntentKey,
                intentType: $intentType,
                channel: $channel,
                content: $content,
                metadata: $metadata,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'delivery_message',
                subjectId: $message->id,
                evidence: [
                    'business_intent_key' => $businessIntentKey,
                    'intent_type' => $intentType->value,
                    'channel' => $channel->value,
                ],
            );

            return $message;
        });
    }
}
