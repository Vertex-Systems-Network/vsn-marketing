<?php

namespace App\Modules\Providers\Domain\Connectors;

enum ReconciliationSource: string
{
    case Polling = 'polling';
    case Webhook = 'webhook';
}
