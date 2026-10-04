<?php

namespace App\Modules\Journeys\Domain;

enum JourneyWaitOutcome: string
{
    case Waiting = 'waiting';
    case Ready = 'ready';
}
