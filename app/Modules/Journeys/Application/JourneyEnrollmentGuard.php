<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyExecutionIdentity;

final class JourneyEnrollmentGuard
{
    /**
     * @param array<string, mixed> $event
     */
    public function key(string $workspaceId, string $journeyVersionId, string $subjectId, array $event): string
    {
        $eventId = (string) ($event['event_id'] ?? '');
        if ($eventId === '') {
            throw new \InvalidArgumentException('canonical event_id is required');
        }

        return JourneyExecutionIdentity::for($workspaceId, $journeyVersionId, $subjectId, $eventId);
    }
}
