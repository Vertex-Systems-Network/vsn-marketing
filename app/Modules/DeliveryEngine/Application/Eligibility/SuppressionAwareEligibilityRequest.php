<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationResult;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;

final readonly class SuppressionAwareEligibilityRequest
{
    public function __construct(
        public EligibilityContext $policyContext,
        public bool $canonicalSuppressionApplies,
        public bool $canonicalObjectionApplies,
        public ?SuppressionSynchronizationResult $providerReconciliation = null,
    ) {}
}
