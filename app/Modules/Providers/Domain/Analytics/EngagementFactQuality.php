<?php

namespace App\Modules\Providers\Domain\Analytics;

use InvalidArgumentException;

final class EngagementFactQuality
{
    /**
     * @param list<EngagementFact> $facts
     */
    public static function validate(array $facts): void
    {
        $seen = [];

        foreach ($facts as $fact) {
            if (!$fact instanceof EngagementFact) {
                throw new InvalidArgumentException('Analytics quality checks require engagement facts.');
            }

            $key = implode('|', [$fact->tenantId, $fact->providerKey, $fact->metric, $fact->sourceLineage, $fact->observedAt]);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate analytics facts are not accepted.');
            }
            $seen[$key] = true;
        }
    }
}
