<?php

namespace App\Modules\Core\Application\Runtime;

use App\Modules\Core\Domain\Contracts\Clock;

final readonly class RuntimeSnapshot
{
    public function __construct(private Clock $clock)
    {
    }

    /** @return array{name:string,environment:string,php:string,time:string} */
    public function toArray(): array
    {
        return [
            'name' => (string) config('app.name'),
            'environment' => (string) app()->environment(),
            'php' => PHP_VERSION,
            'time' => $this->clock->now()->format(DATE_ATOM),
        ];
    }
}
