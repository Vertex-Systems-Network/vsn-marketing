<?php

namespace App\Modules\Core\Domain\Contracts;

interface IdentifierGenerator
{
    public function next(): string;
}
