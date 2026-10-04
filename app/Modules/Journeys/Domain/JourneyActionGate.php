<?php

namespace App\Modules\Journeys\Domain;

/** Fails closed unless all runtime action controls were freshly revalidated. */
final class JourneyActionGate
{
    /** @param array<string, bool> $checks @return list<string> */
    public function blockers(array $checks): array
    {
        $required = ['provider_capability', 'consent', 'suppression_clear', 'authorized', 'quota_available', 'idempotent'];
        $blocked = [];
        foreach ($required as $key) {
            if (($checks[$key] ?? false) !== true) {
                $blocked[] = $key;
            }
        }

        return $blocked;
    }

    /** @param array<string, bool> $checks */
    public function assertAllowed(array $checks): void
    {
        $blockers = $this->blockers($checks);
        if ($blockers !== []) {
            throw new JourneyDefinitionException('action_blocked:'.implode(',', $blockers), '$.action');
        }
    }
}
