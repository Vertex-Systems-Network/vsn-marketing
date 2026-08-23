<?php

namespace App\Modules\Consent\Domain;

enum ConsentDecision: string
{
    case Granted = 'granted';
    case Denied = 'denied';
}
