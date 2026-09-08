<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryMessage;
use App\Modules\DeliveryEngine\Domain\MessageExecutionSnapshot;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\RecipientExecutionSnapshot;
use App\Modules\DeliveryEngine\Domain\RecipientIdentity;

interface DeliveryRepository
{
    public function createMessage(
        string $id,
        string $workspaceId,
        ?string $brandId,
        string $businessIntentKey,
        MessageIntentType $intentType,
        DeliveryChannel $channel,
        array $content,
        array $metadata,
    ): DeliveryMessage;

    public function findMessage(string $workspaceId, ?string $brandScopeId, string $messageId): ?DeliveryMessage;

    public function createMessageSnapshot(string $id, DeliveryMessage $message, string $contentHash): MessageExecutionSnapshot;

    public function createRecipientSnapshot(
        string $id,
        MessageExecutionSnapshot $messageSnapshot,
        RecipientIdentity $recipient,
        string $contentHash,
    ): RecipientExecutionSnapshot;
}
