<?php

namespace App\Modules\Providers\Domain\Connectors;

enum ProviderOperationStatus: string
{
    case Accepted = 'accepted';
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
