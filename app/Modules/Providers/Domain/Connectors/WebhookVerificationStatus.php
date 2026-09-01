<?php

namespace App\Modules\Providers\Domain\Connectors;

enum WebhookVerificationStatus: string
{
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Unsupported = 'unsupported';
    case NotRequired = 'not_required';
}
