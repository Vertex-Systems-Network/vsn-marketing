<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyObservationSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Default fail-closed adapter; a trusted, independently reviewed source is required. */
final class DenyingBoundedAutonomyObservationSource implements BoundedAutonomyObservationSource
{
    public function verifiedCount(TenantContext $scope, string $sourceId, string $metricId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
