<?php

namespace App\Modules\Publishing\Domain\Scheduling;

enum CampaignScheduleMutationType: string
{
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
}
