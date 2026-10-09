<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Independently aggregated, consent-checked frozen experiment assignments. */
interface BoundedAutonomyCanaryCohortSource
{
    public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array;
}
