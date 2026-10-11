<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Explicitly scoped source of synthetic OFFLINE red-team observation fixtures.
 * This is not a provider incident feed or proof of production exploitation.
 */
interface BoundedAutonomyRedTeamEvidenceSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array;
}
