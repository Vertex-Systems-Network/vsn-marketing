<?php

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Events\Domain\CustomerEventSubject;
use App\Modules\Events\Domain\EventType;

interface CustomerEventRepository
{
    public function store(EventType $eventType, CanonicalEvent $event, CustomerEventSubject $subject): bool;

    /** @return list<CanonicalEvent> */
    public function timeline(
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        int $limit,
    ): array;
}
