<?php

namespace App\Modules\Providers\Domain\Social;

enum SocialOperation: string
{
    case Create = 'publication.create';
    case Media = 'publication.media';
    case Schedule = 'publication.schedule';
    case Status = 'publication.status';
    case Edit = 'publication.edit';
    case Delete = 'publication.delete';
    case Analytics = 'publication.analytics';
}
