<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomySafetySnapshotSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/**
 * In-memory view of an already row-locked policy within one transaction.
 * This class must not be used to authorize a provider or external side effect.
 */
final readonly class LockedBoundedAutonomySafetySnapshotSource implements BoundedAutonomySafetySnapshotSource
{
    public function __construct(private array $snapshot)
    {
    }

    public function current(TenantContext $scope, DateTimeImmutable $at): array
    {
        return $this->snapshot;
    }
}
