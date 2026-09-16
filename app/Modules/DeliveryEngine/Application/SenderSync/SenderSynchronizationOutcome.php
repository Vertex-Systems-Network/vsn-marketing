<?php

namespace App\Modules\DeliveryEngine\Application\SenderSync;

enum SenderSynchronizationOutcome: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Timeout = 'timeout';
    case Ambiguous = 'ambiguous';

    public function requiresReconciliation(): bool
    {
        return in_array($this, [self::Timeout, self::Ambiguous], true);
    }
}
