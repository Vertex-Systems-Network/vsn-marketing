<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyRollbackOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Missing provider evidence is unknown and must never be refunded. */
final class DenyingBoundedAutonomyRollbackOutcomeSource implements BoundedAutonomyRollbackOutcomeSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
