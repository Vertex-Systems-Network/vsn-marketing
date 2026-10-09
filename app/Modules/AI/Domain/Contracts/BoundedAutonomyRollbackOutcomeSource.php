<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Independent provider outcome provenance, not an AI narrative or callback. */
interface BoundedAutonomyRollbackOutcomeSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array;
}
