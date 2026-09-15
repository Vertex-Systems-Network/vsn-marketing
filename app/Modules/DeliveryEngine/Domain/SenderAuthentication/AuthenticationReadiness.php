<?php

namespace App\Modules\DeliveryEngine\Domain\SenderAuthentication;

enum AuthenticationReadiness: string
{
    case Ready = 'ready';
    case Unknown = 'unknown';
    case Stale = 'stale';
    case Failed = 'failed';
    case Contradictory = 'contradictory';
}
