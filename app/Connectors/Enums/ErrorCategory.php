<?php

declare(strict_types=1);

namespace App\Connectors\Enums;

enum ErrorCategory: string
{
    case RETRYABLE = 'retryable';
    case RATE_LIMITED = 'rate_limited';
    case AUTHENTICATION = 'authentication';
    case AUTHORIZATION = 'authorization';
    case VALIDATION = 'validation';
    case PERMANENT = 'permanent';
    case UNAVAILABLE = 'unavailable';
}
