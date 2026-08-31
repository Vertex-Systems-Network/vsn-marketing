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

    public function canAdvanceTo(self $next): bool
    {
        if ($this->isTerminal() || $next === self::Unknown) {
            return false;
        }

        if ($next->isTerminal()) {
            return true;
        }

        return $next->progressRank() >= $this->progressRank();
    }

    private function progressRank(): int
    {
        return match ($this) {
            self::Unknown => -1,
            self::Accepted => 0,
            self::Pending => 1,
            self::InProgress => 2,
            self::Succeeded, self::Failed, self::Cancelled => 3,
        };
    }
}
