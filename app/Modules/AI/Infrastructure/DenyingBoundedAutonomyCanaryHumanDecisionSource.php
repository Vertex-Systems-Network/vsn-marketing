<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** No trusted human promotion authority is installed by default. */
final class DenyingBoundedAutonomyCanaryHumanDecisionSource implements BoundedAutonomyCanaryHumanDecisionSource
{
    public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
