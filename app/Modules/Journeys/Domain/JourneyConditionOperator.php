<?php

namespace App\Modules\Journeys\Domain;

enum JourneyConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Exists = 'exists';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case Contains = 'contains';
}
