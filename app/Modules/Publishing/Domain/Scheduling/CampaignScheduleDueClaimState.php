<?php

namespace App\Modules\Publishing\Domain\Scheduling;

enum CampaignScheduleDueClaimState: string
{
    case Leased = 'leased';
    case Emitted = 'emitted';
}
