<?php

namespace App\Modules\Consent\Infrastructure;

use App\Modules\Consent\Domain\Contracts\ConsentTransaction;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseConsentTransaction implements ConsentTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function run(Closure $callback): mixed
    {
        return $this->database->connection()->transaction($callback);
    }
}
