<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Separately trusted read-only source, never an AI model or caller-supplied
 * "verified" flag. No production positive adapter is configured by default.
 */
interface BoundedAutonomyObservationSource
{
    /**
     * Return independently verified, tenant-bound aggregate evidence or null.
     * No raw PII, recipient data, provider credentials or external actions.
     */
    public function verifiedCount(TenantContext $scope, string $sourceId, string $metricId, DateTimeImmutable $at): ?array;
}
