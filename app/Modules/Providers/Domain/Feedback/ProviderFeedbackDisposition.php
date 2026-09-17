<?php

namespace App\Modules\Providers\Domain\Feedback;

enum ProviderFeedbackDisposition: string
{
    case Suppress = 'suppress';
    case Review = 'review';
    case Reject = 'reject';
}
