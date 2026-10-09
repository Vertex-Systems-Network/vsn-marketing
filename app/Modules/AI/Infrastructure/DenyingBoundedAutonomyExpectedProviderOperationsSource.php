<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyExpectedProviderOperationsSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Absence of canonical attempt inventory always blocks rollback readiness. */
final class DenyingBoundedAutonomyExpectedProviderOperationsSource implements BoundedAutonomyExpectedProviderOperationsSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
