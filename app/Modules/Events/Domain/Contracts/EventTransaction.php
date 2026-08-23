<?php

namespace App\Modules\Events\Domain\Contracts;

use Closure;

interface EventTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed;
}
