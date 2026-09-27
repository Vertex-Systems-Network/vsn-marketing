<?php

namespace App\Modules\Journeys\Domain;

use InvalidArgumentException;

final class JourneyDefinitionException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, public readonly string $path = '$.graph')
    {
        parent::__construct($reason.' at '.$path);
    }
}
