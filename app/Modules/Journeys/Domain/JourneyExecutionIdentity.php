<?php

namespace App\Modules\Journeys\Domain;

use JsonException;

final class JourneyExecutionIdentity
{
    /**
     * @throws JsonException
     */
    public static function for(string $workspaceId, string $journeyVersionId, string $subjectId, string $enrollmentId): string
    {
        return self::hashTuple([$workspaceId, $journeyVersionId, $subjectId, $enrollmentId]);
    }

    /**
     * @throws JsonException
     */
    public static function nodeAttempt(string $executionId, string $nodeId, int $attempt): string
    {
        return self::hashTuple([$executionId, $nodeId, $attempt]);
    }

    /**
     * @param  list<string|int>  $parts
     *
     * @throws JsonException
     */
    private static function hashTuple(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
