<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryFailoverEligibility
{
    public function __construct(
        public DeliveryRouteAcceptanceState $previousRouteAcceptance,
        public bool $sameWorkspace,
        public bool $tenantChecksPass,
        public bool $capabilityCompatible,
        public bool $policyAllows,
        public bool $connectionReady,
        public bool $quotaAvailable,
        public bool $breakerAllows,
    ) {}
}
