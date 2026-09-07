<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseDeliveryTransaction implements DeliveryTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function run(callable $callback): mixed
    {
        return $this->database->connection()->transaction($callback);
    }
}
