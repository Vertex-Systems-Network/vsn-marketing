<?php

namespace App\Modules\Providers\Domain\Messaging;

final readonly class MessagingDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $offlinePreparationAllowed,
        public bool $liveDeliveryAllowed,
        public array $reasons,
    ) {}
}
