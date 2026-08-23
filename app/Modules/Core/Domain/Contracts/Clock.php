<?php

namespace App\Modules\Core\Domain\Contracts;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
