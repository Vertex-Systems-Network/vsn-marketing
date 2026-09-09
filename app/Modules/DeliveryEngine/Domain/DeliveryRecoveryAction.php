<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryRecoveryAction: string
{
    case FailOperation = 'fail_operation';
    case HoldConnection = 'hold_connection';
    case RetryWait = 'retry_wait';
    case RetrySameRoute = 'retry_same_route';
    case Reconcile = 'reconcile';
    case MarkAccepted = 'mark_accepted';
    case StopRetrying = 'stop_retrying';
}
