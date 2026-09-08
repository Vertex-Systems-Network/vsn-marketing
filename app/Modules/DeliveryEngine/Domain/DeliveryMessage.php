<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryMessage
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $brandId,
        public string $businessIntentKey,
        public MessageIntentType $intentType,
        public DeliveryChannel $channel,
        public array $content,
        public array $metadata,
    ) {}
}
