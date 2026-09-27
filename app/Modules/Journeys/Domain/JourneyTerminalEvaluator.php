<?php

namespace App\Modules\Journeys\Domain;

use App\Modules\Events\Domain\CanonicalEvent;

/** Matches goal and exit nodes against tenant-scoped canonical event types. */
final class JourneyTerminalEvaluator
{
    /** @param array<string, mixed> $node */
    public function evaluate(string $workspaceId, array $node, CanonicalEvent $event): JourneyTerminalOutcome
    {
        if ($workspaceId === '' || $event->workspaceId !== $workspaceId) {
            throw new JourneyDefinitionException('workspace_scope_mismatch', '$.event.workspace_id');
        }
        $type = $node['type'] ?? null;
        if (! in_array($type, ['goal', 'exit'], true) || ! is_array($node['config'] ?? null)) {
            throw new JourneyDefinitionException('invalid_terminal_node', '$.node');
        }
        if (($node['config']['event'] ?? null) !== $event->eventType) {
            return JourneyTerminalOutcome::Unmatched;
        }

        return $type === 'goal' ? JourneyTerminalOutcome::GoalAchieved : JourneyTerminalOutcome::Exited;
    }
}
