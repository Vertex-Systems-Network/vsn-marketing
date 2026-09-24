<?php

namespace App\Modules\Publishing\Domain\Publication;

enum PublicationAggregateState: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case PartialSuccess = 'partial_success';
    case Succeeded = 'succeeded';
    case FailedRetriable = 'failed_retriable';
    case FailedTerminal = 'failed_terminal';
    case Cancelled = 'cancelled';
}
