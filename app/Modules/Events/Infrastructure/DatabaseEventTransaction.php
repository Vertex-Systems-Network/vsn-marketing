<?php

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventTransaction;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseEventTransaction implements EventTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function run(Closure $callback): mixed
    {
        return $this->database->connection()->transaction($callback);
    }
}
