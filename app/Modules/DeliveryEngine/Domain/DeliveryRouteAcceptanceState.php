<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryRouteAcceptanceState: string
{
    case Accepted = 'accepted';
    case KnownNotAccepted = 'known_not_accepted';
    case Ambiguous = 'ambiguous';
}
