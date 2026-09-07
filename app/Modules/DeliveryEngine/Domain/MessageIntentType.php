<?php

namespace App\Modules\DeliveryEngine\Domain;

enum MessageIntentType: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';
}
