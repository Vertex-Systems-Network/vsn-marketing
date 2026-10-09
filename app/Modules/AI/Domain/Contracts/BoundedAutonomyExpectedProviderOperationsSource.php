<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Authenticated canonical operations inventory, independent of provider
 * readback/callbacks. Must be read from durable server-owned attempt records.
 * Never infer the expected attempt list from AI text or provider assertions.
 */
interface BoundedAutonomyExpectedProviderOperationsSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array;
}
