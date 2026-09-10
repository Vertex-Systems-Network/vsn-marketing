<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryFailoverEligibility;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationSnapshot;
use DateTimeImmutable;

interface DeliveryFailoverEligibilityRepository
{
    public function assess(
        DeliveryReconciliationSnapshot $snapshot,
        string $alternateProviderId,
        string $alternateProviderConnectionId,
        DateTimeImmutable $observedAt,
    ): DeliveryFailoverEligibility;
}
