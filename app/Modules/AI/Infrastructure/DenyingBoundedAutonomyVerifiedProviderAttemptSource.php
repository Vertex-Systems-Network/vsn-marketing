<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyVerifiedProviderAttemptSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** No provider attestation is configured: every external outcome stays unknown. */
final class DenyingBoundedAutonomyVerifiedProviderAttemptSource implements BoundedAutonomyVerifiedProviderAttemptSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
