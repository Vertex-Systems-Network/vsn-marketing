<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

enum SenderPurpose: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';
    case Operational = 'operational';
}
