<?php

declare(strict_types=1);

namespace App\Connectors\Dedup;

/**
 * In-memory dedup store for tests and simple deployments. Not suitable for multi-process production.
 */
class InMemoryDedupStore implements DedupStoreInterface
{
    private array $store = [];

    public function has(string $id): bool
    {
        $now = time();
        if (isset($this->store[$id]) && $this->store[$id] > $now) {
            return true;
        }
        // clean up expired
        unset($this->store[$id]);
        return false;
    }

    public function record(string $id, int $ttlSeconds = 300): void
    {
        $this->store[$id] = time() + $ttlSeconds;
    }
}
