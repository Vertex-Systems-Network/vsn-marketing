<?php

namespace App\Modules\Contacts\Domain\Contracts;

use Closure;

interface ContactTransaction
{
    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed;
}
