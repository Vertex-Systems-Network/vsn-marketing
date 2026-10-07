<?php

namespace App\Modules\Providers\Domain\Analytics;

use InvalidArgumentException;

final readonly class EngagementFact
{
    public function __construct(
        public string $tenantId,
        public string $providerKey,
        public string $metric,
        public int $value,
        public string $sourceLineage,
        public string $observedAt,
        public bool $isTotalKnown = false,
    ) {
        foreach ([$this->tenantId, $this->providerKey, $this->metric, $this->sourceLineage, $this->observedAt] as $field) {
            if (trim($field) === '') {
                throw new InvalidArgumentException('Analytics facts require tenant, source, metric and lineage.');
            }
        }
        if ($this->value < 0) {
            throw new InvalidArgumentException('Analytics fact values cannot be negative.');
        }
    }

    public function isUnknownTotal(): bool
    {
        return ! $this->isTotalKnown;
    }
}
