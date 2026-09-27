<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyExecutionIdentity;

final class JourneyEnrollmentGuard
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function key(string $workspaceId, string $journeyVersionId, string $subjectId, array $event): string
    {
        $eventId = $event['event_id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            throw new \InvalidArgumentException('canonical event_id is required');
        }

        return JourneyExecutionIdentity::for($workspaceId, $journeyVersionId, $subjectId, $eventId);
    }
}
