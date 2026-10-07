<?php

namespace App\Modules\Providers\Domain\Listening;

use InvalidArgumentException;

enum ListeningSourceType: string
{
    case OfficialApi = 'official_api';
    case OfficialWebhook = 'official_webhook';
}
