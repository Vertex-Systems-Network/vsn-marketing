<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

interface DeliveryTransaction
{
    public function run(callable $callback): mixed;
}
