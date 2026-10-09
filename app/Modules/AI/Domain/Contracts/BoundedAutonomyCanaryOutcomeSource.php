<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Independent verifier of admitted, de-duplicated offline cohort outcomes. */
interface BoundedAutonomyCanaryOutcomeSource
{
    public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array;
}
