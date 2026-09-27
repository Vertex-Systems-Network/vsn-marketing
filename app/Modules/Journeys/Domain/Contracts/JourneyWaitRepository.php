<?php

namespace App\Modules\Journeys\Domain\Contracts;

use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyWaitRecord;
use DateTimeImmutable;

interface JourneyWaitRepository
{
    /** Store one bounded wait. Matching retries return false; identity reuse with different data fails closed. */
    public function store(DurableJourneyWait $wait, ?array $predicate = null): bool;

    /** @return list<JourneyWaitRecord> Workspace-scoped pending waits ordered by wake instant and stable id. */
    public function due(string $workspaceId, DateTimeImmutable $now, int $limit = 100): array;
}
