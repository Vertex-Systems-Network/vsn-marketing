<?php

namespace App\Modules\Providers\Domain\Contracts;

interface ProviderTransaction
{
    public function run(callable $callback): mixed;
}
