<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;

final readonly class SuppressionAwareEligibilityResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public EligibilityOutcome $outcome,
        public bool $suppressionAuthorityApplied,
        public bool $providerReconciliationRestoredEligibility,
        public array $reasons,
    ) {}
}
