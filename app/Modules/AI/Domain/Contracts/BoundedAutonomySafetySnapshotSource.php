<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Trusted server-side governance snapshot, not a model-supplied approval.
 * Implementations must read current global/workspace stops and counters.
 * A null result holds all work; an optimistic default is never permitted.
 */
interface BoundedAutonomySafetySnapshotSource
{
    public function current(TenantContext $scope, DateTimeImmutable $at): ?array;
}
