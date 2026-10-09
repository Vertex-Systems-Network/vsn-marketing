<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * An independent provider-reconciliation adapter must verify each receipt,
 * request idempotency key and completeness against a source other than an
 * agent's prose or untrusted callback before exposing this evidence.
 */
interface BoundedAutonomyVerifiedProviderAttemptSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array;
}
