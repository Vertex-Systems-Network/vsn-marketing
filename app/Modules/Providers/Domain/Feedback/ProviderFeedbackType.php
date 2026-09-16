<?php

namespace App\Modules\Providers\Domain\Feedback;

enum ProviderFeedbackType: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
    case Unsubscribe = 'unsubscribe';
}
