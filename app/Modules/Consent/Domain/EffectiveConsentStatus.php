<?php

namespace App\Modules\Consent\Domain;

enum EffectiveConsentStatus: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Missing = 'missing';
    case Ambiguous = 'ambiguous';
}
