<?php

namespace App\Modules\Providers\Domain\Social;

enum SocialPlatform: string
{
    case LinkedIn = 'linkedin';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Threads = 'threads';
    case Pinterest = 'pinterest';
}
