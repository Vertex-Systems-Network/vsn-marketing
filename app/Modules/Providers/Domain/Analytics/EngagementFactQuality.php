<?php

namespace App\Modules\Providers\Domain\Analytics;

use InvalidArgumentException;

final class EngagementFactQuality
{
    public static function validate(array $facts): void
    {
        $seen = [];

        foreach ($facts as $fact) {
            if (! $fact instanceof EngagementFact) {
                throw new InvalidArgumentException('Analytics quality checks require engagement facts.');
            }

            $key = implode('|', [$fact->tenantId, $fact->brandId ?? '', $fact->providerKey, $fact->metric, $fact->sourceLineage, $fact->observedAt]);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate analytics facts are not accepted.');
            }
            $seen[$key] = true;

            $definition = $fact->definition();
            if (($definition['cross_provider_equivalent'] ?? true) !== false || ($definition['semantics'] ?? null) !== 'provider_reported_aggregate') {
                throw new InvalidArgumentException('Provider metric semantics must remain source-specific.');
            }
        }
    }
}
