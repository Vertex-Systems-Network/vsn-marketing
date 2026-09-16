<?php

namespace App\Modules\DeliveryEngine\Application\SuppressionSync;

enum SuppressionSynchronizationOutcome: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Timeout = 'timeout';
    case Ambiguous = 'ambiguous';

    public function requiresReconciliation(): bool
    {
        return $this !== self::Confirmed;
    }
}
