<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Fail-closed: agent output is never a verified conversion or exposure. */
final class DenyingBoundedAutonomyCanaryOutcomeSource implements BoundedAutonomyCanaryOutcomeSource
{
    public function snapshot(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
