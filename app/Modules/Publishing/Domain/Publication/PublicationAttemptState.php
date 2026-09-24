<?php

namespace App\Modules\Publishing\Domain\Publication;

enum PublicationAttemptState: string
{
    case Prepared = 'prepared';
    case Dispatching = 'dispatching';
    case Published = 'published';
    case FailedRetriable = 'failed_retriable';
    case FailedTerminal = 'failed_terminal';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Prepared => in_array($next, [
                self::Dispatching,
                self::FailedTerminal,
                self::Cancelled,
            ], true),
            self::Dispatching => in_array($next, [
                self::Published,
                self::FailedRetriable,
                self::FailedTerminal,
            ], true),
            self::FailedRetriable => in_array($next, [
                self::Dispatching,
                self::FailedTerminal,
                self::Cancelled,
            ], true),
            self::Published, self::FailedTerminal, self::Cancelled => false,
        };
    }
}
