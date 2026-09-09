<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryReconciliationResolution: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case NotAcceptedRetrySafe = 'not_accepted_retry_safe';
    case OperatorResolutionRequired = 'operator_resolution_required';
}
