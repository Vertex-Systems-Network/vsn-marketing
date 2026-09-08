<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryPriorityClass: string
{
    case High = 'high';
    case Normal = 'normal';
    case Bulk = 'bulk';
}
