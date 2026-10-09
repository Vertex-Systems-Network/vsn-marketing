<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Defaults to unknown outcome, never fabricated success or retry authority. */
final class DenyingBoundedAutonomyOutcomeSource implements BoundedAutonomyOutcomeSource
{
    public function verifiedOutcome(TenantContext $scope, string $attemptId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
