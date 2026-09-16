<?php

namespace App\Modules\DeliveryEngine\Application\SenderVerification;

enum SenderVerificationObservationOutcome: string
{
    case Completed = 'completed';
    case Timeout = 'timeout';
    case Ambiguous = 'ambiguous';
}
