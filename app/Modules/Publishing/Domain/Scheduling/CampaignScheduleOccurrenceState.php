<?php

namespace App\Modules\Publishing\Domain\Scheduling;

enum CampaignScheduleOccurrenceState: string
{
    case MissedNeedsReschedule = 'missed_needs_reschedule';
}
