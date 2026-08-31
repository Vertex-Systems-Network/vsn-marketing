<?php

declare(strict_types=1);

namespace App\Connectors\Dedup;

use Illuminate\Support\Facades\DB;

class DatabaseDedupStore implements DedupStoreInterface
{
    private string $table = 'connector_dedup';

    public function has(string $id): bool
    {
        $row = DB::table($this->table)
            ->where('id', $id)
            ->where('expires_at', '>', now())
            ->first();

        return (bool)$row;
    }

    public function record(string $id, int $ttlSeconds = 300): void
    {
        $expiresAt = now()->addSeconds($ttlSeconds);

        DB::table($this->table)->insertOrIgnore([
            'id' => $id,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
