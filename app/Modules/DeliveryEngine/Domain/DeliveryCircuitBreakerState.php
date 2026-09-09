<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryCircuitBreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
