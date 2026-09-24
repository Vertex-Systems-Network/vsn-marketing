<?php

namespace App\Modules\Publishing\Domain\Scheduling;

enum CampaignScheduleStrategy: string
{
    case FixedInstant = 'fixed_instant';
    case QueueNextSlot = 'queue_next_slot';
}
