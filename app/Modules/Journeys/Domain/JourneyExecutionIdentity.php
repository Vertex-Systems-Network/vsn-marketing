<?php

namespace App\Modules\Journeys\Domain;

final class JourneyExecutionIdentity
{
    public static function for(string $workspaceId, string $journeyVersionId, string $subjectId, string $enrollmentId): string
    {
        return hash('sha256', implode('|', [$workspaceId, $journeyVersionId, $subjectId, $enrollmentId]));
    }

    public static function nodeAttempt(string $executionId, string $nodeId, int $attempt): string
    {
        return hash('sha256', implode('|', [$executionId, $nodeId, (string) $attempt]));
    }
}
