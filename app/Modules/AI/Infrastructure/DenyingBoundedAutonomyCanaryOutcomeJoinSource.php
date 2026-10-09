<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeJoinSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** No independently verified outcome join exists by default. */
final class DenyingBoundedAutonomyCanaryOutcomeJoinSource implements BoundedAutonomyCanaryOutcomeJoinSource
{
    public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
