<?php

namespace App\Modules\Publishing\Domain\Publication;

enum PublicationTargetOutcomeState: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case FailedRetriable = 'failed_retriable';
    case FailedTerminal = 'failed_terminal';
    case FailedUnclassified = 'failed_unclassified';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
