<?php

namespace App\Modules\Events\Domain;

final readonly class CustomerEventPersistenceResult
{
    public function __construct(
        public CanonicalEvent $event,
        public EventType $eventType,
        public bool $inserted,
    ) {}
}
