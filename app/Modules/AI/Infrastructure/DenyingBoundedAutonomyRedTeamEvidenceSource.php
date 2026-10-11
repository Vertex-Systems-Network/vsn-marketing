<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyRedTeamEvidenceSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** No production or synthetic red-team incident evidence is fabricated by default. */
final class DenyingBoundedAutonomyRedTeamEvidenceSource implements BoundedAutonomyRedTeamEvidenceSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
