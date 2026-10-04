<?php

namespace App\Modules\Journeys\Domain;

/** Event-time ordering with stable kind/key tie-breaks for simultaneous triggers. */
final class JourneyTriggerOrdering
{
    public function compare(JourneyTrigger $left, JourneyTrigger $right): int
    {
        if ($left->workspaceId !== $right->workspaceId) {
            throw new JourneyDefinitionException('workspace_scope_mismatch', '$.trigger.workspace_id');
        }
        $byTime = $left->effectiveAt <=> $right->effectiveAt;
        if ($byTime !== 0) {
            return $byTime;
        }

        return [$left->kind, $left->key] <=> [$right->kind, $right->key];
    }
}
