<?php

declare(strict_types=1);

namespace App\Connectors\Dedup;

interface DedupStoreInterface
{
    public function has(string $id): bool;

    public function record(string $id, int $ttlSeconds = 300): void;
}
