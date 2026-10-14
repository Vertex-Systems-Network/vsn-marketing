<?php

namespace App\Modules\Identity\Domain\Governance;

/**
 * Conservative admission for a proposed workspace data flow. A configured
 * location must cover every surface in the workflow, including downstream
 * processing. No region is considered supported merely by appearing here.
 */
final class RegionalDataAdmission
{
    public const SURFACES = ['database', 'queue', 'files', 'logs', 'backups', 'analytics', 'ai', 'provider'];

    /** @param array<string, string> $destinations */
    public function allows(string $approvedRegion, array $destinations, bool $externalEgressApproved): bool
    {
        if ($approvedRegion === '' || count($destinations) !== count(self::SURFACES)) {
            return false;
        }

        foreach (self::SURFACES as $surface) {
            $destination = $destinations[$surface] ?? null;
            if (in_array($surface, ['ai', 'provider'], true) && $destination === 'disabled') {
                continue;
            }
            if (! is_string($destination) || $destination === '' || ! hash_equals($approvedRegion, $destination)) {
                return false;
            }

            if (in_array($surface, ['ai', 'provider'], true) && ! $externalEgressApproved) {
                return false;
            }
        }

        return true;
    }
}
