<?php

namespace App\Modules\Providers\Domain\Feedback;

enum ProviderFeedbackClassification: string
{
    case PermanentBounce = 'permanent_bounce';
    case TransientBounce = 'transient_bounce';
    case Complaint = 'complaint';
    case AcceptedUnsubscribe = 'accepted_unsubscribe';
}
