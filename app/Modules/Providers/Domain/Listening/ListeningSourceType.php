<?php

namespace App\Modules\Providers\Domain\Listening;

enum ListeningSourceType: string
{
    case OfficialApi = 'official_api';
    case OfficialWebhook = 'official_webhook';
}
