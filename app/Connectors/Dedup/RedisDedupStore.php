<?php

declare(strict_types=1);

namespace App\Connectors\Dedup;

use Illuminate\Support\Facades\Cache;

class RedisDedupStore implements DedupStoreInterface
{
    private string $prefix = 'connector:dedup:';

    public function has(string $id): bool
    {
        $key = $this->prefix . $id;
        return Cache::has($key);
    }

    public function record(string $id, int $ttlSeconds = 300): void
    {
        $key = $this->prefix . $id;
        Cache::put($key, true, $ttlSeconds);
    }
}
