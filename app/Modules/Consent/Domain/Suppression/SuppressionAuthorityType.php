<?php

namespace App\Modules\Consent\Domain\Suppression;

enum SuppressionAuthorityType: string
{
    case Unsubscribe = 'unsubscribe';
    case Objection = 'objection';
    case PreferenceOptOut = 'preference_opt_out';
    case Bounce = 'bounce';
    case Complaint = 'complaint';
}
