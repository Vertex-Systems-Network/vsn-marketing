<?php

namespace App\Modules\Providers\Infrastructure;

use App\Modules\Providers\Domain\Contracts\ProviderTransaction;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseProviderTransaction implements ProviderTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function run(callable $callback): mixed
    {
        return $this->database->connection()->transaction($callback);
    }
}
