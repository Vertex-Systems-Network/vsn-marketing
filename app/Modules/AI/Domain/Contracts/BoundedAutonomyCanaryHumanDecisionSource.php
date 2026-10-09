<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Independent human decision provenance for OFFLINE canary review.
 *
 * A positive record is allowed only from a source that authenticates the
 * human, verifies current workspace permission and reads the latest decision.
 * Model output, tool proposals and client payloads cannot supply this source.
 */
interface BoundedAutonomyCanaryHumanDecisionSource
{
    public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array;
}
