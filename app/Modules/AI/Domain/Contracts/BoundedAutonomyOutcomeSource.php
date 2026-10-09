<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Independently verified outcome facts only. Never use a model's description
 * of provider state as evidence of success, failure or retry safety.
 */
interface BoundedAutonomyOutcomeSource
{
    public function verifiedOutcome(TenantContext $scope, string $attemptId, DateTimeImmutable $at): ?array;
}
