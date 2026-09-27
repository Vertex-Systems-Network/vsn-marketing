<?php

namespace App\Modules\Journeys\Domain;

enum JourneyTerminalOutcome: string
{
    case Unmatched = 'unmatched';
    case GoalAchieved = 'goal_achieved';
    case Exited = 'exited';
}
