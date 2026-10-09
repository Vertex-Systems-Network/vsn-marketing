<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** No positive cohort or provider evidence can be inferred by default. */
final class DenyingBoundedAutonomyCanaryCohortSource implements BoundedAutonomyCanaryCohortSource
{
    public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
