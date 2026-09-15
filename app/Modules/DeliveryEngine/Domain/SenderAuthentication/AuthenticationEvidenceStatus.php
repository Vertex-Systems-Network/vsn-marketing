<?php

namespace App\Modules\DeliveryEngine\Domain\SenderAuthentication;

enum AuthenticationEvidenceStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unknown = 'unknown';
    case Contradictory = 'contradictory';
}
