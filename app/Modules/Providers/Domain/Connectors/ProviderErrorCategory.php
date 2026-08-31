<?php

namespace App\Modules\Providers\Domain\Connectors;

enum ProviderErrorCategory: string
{
    case Retryable = 'retryable';
    case RateLimited = 'rate_limited';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Validation = 'validation';
    case Unavailable = 'unavailable';
    case Permanent = 'permanent';
    case Unknown = 'unknown';
}
