<?php

namespace App\Modules\Providers\Domain\Community;

enum CommunityItemType: string
{
    case Comment = 'comment';
    case Mention = 'mention';
    case Message = 'message';
}
