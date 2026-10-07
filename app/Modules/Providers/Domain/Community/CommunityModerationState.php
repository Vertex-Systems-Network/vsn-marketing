<?php

namespace App\Modules\Providers\Domain\Community;

enum CommunityModerationState: string
{
    case Open = 'open';
    case Hidden = 'hidden';
    case Resolved = 'resolved';
}
