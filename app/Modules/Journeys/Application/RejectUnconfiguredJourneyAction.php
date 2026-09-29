<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyDefinitionException;

final class RejectUnconfiguredJourneyAction implements JourneyActionExecutor
{
    public function checks(string $workspaceId, string $subjectId, array $node): array
    {
        return [];
    }

    public function execute(string $workspaceId, string $subjectId, array $node, string $attemptKey): void
    {
        throw new JourneyDefinitionException('action_adapter_unconfigured', '$.action');
    }
}
