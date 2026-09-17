<?php

namespace App\Modules\DeliveryEngine\Domain\Deliverability;

enum DeliverabilitySignalKind: string
{
    case SenderAuthentication = 'sender_authentication';
    case Complaint = 'complaint';
    case Bounce = 'bounce';
    case Delivery = 'delivery';
    case Reputation = 'reputation';
    case Health = 'health';
}
