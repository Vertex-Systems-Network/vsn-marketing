<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyApprovalSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** No positive approval source or production execution path exists by default. */
final class DenyingBoundedAutonomyApprovalSource implements BoundedAutonomyApprovalSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
