<?php

namespace App\Modules\Journeys\Domain;

enum JourneyEdgeType: string
{
    case Default = 'default';
    case Success = 'success';
    case Failure = 'failure';
    case True = 'true';
    case False = 'false';
}
