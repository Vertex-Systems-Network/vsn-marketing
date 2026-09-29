<?php

namespace App\Modules\Journeys\Application;

/** Provider adapters must re-evaluate every policy input and use the attempt key for idempotency. */
interface JourneyActionExecutor
{
    /** @param array<string, mixed> $node @return array<string, bool> */
    public function checks(string $workspaceId, string $subjectId, array $node): array;

    /** @param array<string, mixed> $node */
    public function execute(string $workspaceId, string $subjectId, array $node, string $attemptKey): void;
}
