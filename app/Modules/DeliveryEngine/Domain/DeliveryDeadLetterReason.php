<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryDeadLetterReason: string
{
    case PermanentFailure = 'permanent_failure';
    case RetryBudgetExhausted = 'retry_budget_exhausted';
    case OperationExpired = 'operation_expired';
    case InvariantCorruption = 'invariant_corruption';
}
