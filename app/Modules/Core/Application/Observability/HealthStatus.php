<?php

namespace App\Modules\Core\Application\Observability;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

final readonly class HealthStatus
{
    public function __construct(
        private DatabaseManager $database,
        private CacheManager $cache,
    ) {
    }

    /** @return array{status:string,checks:array{database:string,cache:string}} */
    public function readiness(): array
    {
        $database = $this->databaseCheck();
        $cache = $this->cacheCheck();

        return [
            'status' => $database === 'ok' && $cache === 'ok' ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
                'cache' => $cache,
            ],
        ];
    }

    private function databaseCheck(): string
    {
        try {
            $this->database->connection()->getPdo();

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
    }

    private function cacheCheck(): string
    {
        $key = 'vsn:health:'.Str::uuid()->toString();

        try {
            $store = $this->cache->store();
            $store->put($key, 'ok', 5);
            $healthy = $store->get($key) === 'ok';
            $store->forget($key);

            return $healthy ? 'ok' : 'failed';
        } catch (Throwable) {
            return 'failed';
        }
    }
}
