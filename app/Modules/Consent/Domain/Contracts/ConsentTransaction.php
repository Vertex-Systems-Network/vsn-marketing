<?php

namespace App\Modules\Consent\Domain\Contracts;

use Closure;

interface ConsentTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed;
}
