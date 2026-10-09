<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * Source MUST independently prove approver authority, current latest
 * decision and immutable binding. Never accept a model's claimed approval.
 */
interface BoundedAutonomyApprovalSource
{
    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array;
}
