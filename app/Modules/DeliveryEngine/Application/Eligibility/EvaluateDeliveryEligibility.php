<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;

final class EvaluateDeliveryEligibility
{
    public function evaluate(EligibilityContext $context): EligibilityOutcome
    {
        if ($context->suppressionApplies || $context->objectionApplies) {
            return EligibilityOutcome::Deny;
        }

        if (! $context->hasMinimumPolicyContext()) {
            return EligibilityOutcome::Unknown;
        }

        if ($context->jurisdictionPolicyOutcome !== EligibilityOutcome::Allow) {
            return $context->jurisdictionPolicyOutcome;
        }

        if ($context->messagePurpose === MessageIntentType::Marketing) {
            if ($context->policyBasis === PolicyBasisType::None || ! $context->policyBasisEvidencePresent) {
                return EligibilityOutcome::Review;
            }
        }

        return EligibilityOutcome::Allow;
    }
}
