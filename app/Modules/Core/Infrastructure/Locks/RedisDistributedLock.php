<?php

namespace App\Modules\Core\Infrastructure\Locks;

use App\Modules\Core\Domain\Contracts\DistributedLock;
use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use LogicException;

final class RedisDistributedLock implements DistributedLock
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    public function run(string $name, int $seconds, Closure $criticalSection): bool
    {
        $repository = $this->cache->store('redis');

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('The Redis cache store must resolve to a Laravel cache repository.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The Redis cache store must support distributed locks.');
        }

        $executed = false;

        $store->lock($name, $seconds)->get(
            static function () use (&$executed, $criticalSection): void {
                $executed = true;
                $criticalSection();
            }
        );

        return $executed;
    }
}
