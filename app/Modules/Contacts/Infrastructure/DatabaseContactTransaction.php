<?php

namespace App\Modules\Contacts\Infrastructure;

use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseContactTransaction implements ContactTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function run(Closure $callback): mixed
    {
        return $this->database->connection()->transaction($callback);
    }
}
