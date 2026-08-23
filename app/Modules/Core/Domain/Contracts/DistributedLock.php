<?php

namespace App\Modules\Core\Domain\Contracts;

use Closure;

interface DistributedLock
{
    public function run(string $name, int $seconds, Closure $criticalSection): bool;
}
