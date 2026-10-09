<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomySafetySnapshotSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Fail-closed until independent, durable governance sources are installed. */
final class DenyingBoundedAutonomySafetySnapshotSource implements BoundedAutonomySafetySnapshotSource
{
    public function current(TenantContext $scope, DateTimeImmutable $at): ?array
    {
        return null;
    }
}
