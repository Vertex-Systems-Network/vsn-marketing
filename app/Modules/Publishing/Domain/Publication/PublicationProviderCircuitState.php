<?php

namespace App\Modules\Publishing\Domain\Publication;

enum PublicationProviderCircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
