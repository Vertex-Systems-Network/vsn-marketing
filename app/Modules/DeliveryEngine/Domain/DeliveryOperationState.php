<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryOperationState: string
{
    case Scheduled = 'scheduled';
    case Ready = 'ready';
    case Backpressured = 'backpressured';
    case Leased = 'leased';
    case Reconciling = 'reconciling';
    case Held = 'held';
    case Accepted = 'accepted';
    case DeadLettered = 'dead_lettered';
}
