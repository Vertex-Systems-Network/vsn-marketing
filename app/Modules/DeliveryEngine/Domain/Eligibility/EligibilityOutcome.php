<?php

namespace App\Modules\DeliveryEngine\Domain\Eligibility;

enum EligibilityOutcome: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Review = 'review';
    case Unknown = 'unknown';
}
