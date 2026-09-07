<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class MessageExecutionSnapshot
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $messageId,
        public int $version,
        public string $businessIntentKey,
        public MessageIntentType $intentType,
        public DeliveryChannel $channel,
        public array $content,
        public array $metadata,
        public string $contentHash,
    ) {}
}
