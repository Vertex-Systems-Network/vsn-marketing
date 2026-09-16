<?php

namespace App\Modules\Consent\Domain\Suppression;

enum PreferenceDecision: string
{
    case OptIn = 'opt_in';
    case OptOut = 'opt_out';
}
