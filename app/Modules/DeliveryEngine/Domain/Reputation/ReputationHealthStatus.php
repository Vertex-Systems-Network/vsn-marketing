<?php

namespace App\Modules\DeliveryEngine\Domain\Reputation;

enum ReputationHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Blocked = 'blocked';
    case Unknown = 'unknown';
}
