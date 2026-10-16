<?php

namespace App\Modules\Identity\Domain\Governance;

/** Pure fail-closed decision: data for a workspace region may only reach a surface attested in that region. */
final class RegionalDataAdmission
{
    public const SURFACES = ['database', 'queue', 'files', 'logs', 'backups', 'analytics', 'ai_egress', 'provider_egress'];

    /** @param list<string> $supportedRegions */
    public function allows(array $supportedRegions, ?string $workspaceRegion, string $surface, ?string $destinationRegion): bool
    {
        return in_array($surface, self::SURFACES, true)
            && is_string($workspaceRegion) && in_array($workspaceRegion, $supportedRegions, true)
            && is_string($destinationRegion) && $destinationRegion !== ''
            && hash_equals($workspaceRegion, strtolower(trim($destinationRegion)));
    }
}
