<?php

namespace App\Modules\DeliveryEngine\Domain\Eligibility;

enum PolicyBasisType: string
{
    case ExplicitConsent = 'explicit_consent';
    case CommercialSoftOptIn = 'commercial_soft_opt_in';
    case CharitablePurposeSoftOptIn = 'charitable_purpose_soft_opt_in';
    case OtherReviewedBasis = 'other_reviewed_basis';
    case None = 'none';
}
