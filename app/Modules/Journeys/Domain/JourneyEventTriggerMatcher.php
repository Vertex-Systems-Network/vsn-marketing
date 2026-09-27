<?php

namespace App\Modules\Journeys\Domain;

use App\Modules\Events\Domain\CanonicalEvent;

/** Matches event trigger nodes against the canonical, tenant-scoped event stream. */
final class JourneyEventTriggerMatcher
{
    /** @param array<string, mixed> $node */
    public function match(string $workspaceId, array $node, CanonicalEvent $event): ?JourneyTrigger
    {
        if ($workspaceId === '' || $event->workspaceId !== $workspaceId) {
            throw new JourneyDefinitionException('workspace_scope_mismatch', '$.event.workspace_id');
        }
        if (($node['type'] ?? null) !== 'trigger' || ! is_array($node['config'] ?? null) || ! is_string($node['config']['event'] ?? null)) {
            throw new JourneyDefinitionException('invalid_trigger_node', '$.node');
        }
        if ($node['config']['event'] !== $event->eventType) {
            return null;
        }

        return JourneyTrigger::event($workspaceId, $event->eventId, $event->occurredAt);
    }
}
