<?php

namespace App\Modules\Core\Infrastructure\Locks;

use App\Modules\Core\Domain\Contracts\DistributedLock;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

final class RedisDistributedLock implements DistributedLock
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    public function run(string $name, int $seconds, Closure $criticalSection): bool
    {
        $executed = false;

        $this->cache->store('redis')->lock($name, $seconds)->get(
            static function () use (&$executed, $criticalSection): void {
                $executed = true;
                $criticalSection();
            }
        );

        return $executed;
    }
}
