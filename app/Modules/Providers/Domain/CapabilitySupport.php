<?php

namespace App\Modules\Providers\Domain;

enum CapabilitySupport: string
{
    case Unknown = 'unknown';
    case Unsupported = 'unsupported';
    case Supported = 'supported';
}
