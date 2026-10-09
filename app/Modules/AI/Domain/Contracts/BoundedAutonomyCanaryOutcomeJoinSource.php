<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Independent, read-only evidence of an exact frozen cohort-to-outcome join. */
interface BoundedAutonomyCanaryOutcomeJoinSource
{
    public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array;
}
