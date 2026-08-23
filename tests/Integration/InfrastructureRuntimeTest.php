<?php

use App\Modules\Core\Domain\Contracts\ObjectStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('runs against PostgreSQL 18', function () {
    $version = (string) DB::selectOne('show server_version')->server_version;

    expect(config('database.default'))->toBe('pgsql')
        ->and($version)->toStartWith('18.');
});

it('uses Redis for cache locks and queue transport', function () {
    Cache::store('redis')->put('task-0004-cache-probe', 'ok', 30);
    $lock = Cache::store('redis')->lock('task-0004-lock-probe', 10);

    expect(Cache::store('redis')->get('task-0004-cache-probe'))->toBe('ok')
        ->and($lock->get())->toBeTrue()
        ->and(config('queue.default'))->toBe('redis')
        ->and(config('queue.connections.redis.connection'))->toBe('queue');

    $lock->release();
    Queue::connection('redis')->size('default');
});

it('round-trips objects through the configured S3-compatible store', function () {
    $store = app(ObjectStore::class);
    $path = 'integration/'.str()->uuid().'.txt';

    $store->put($path, 'minio-ok');

    expect($store->exists($path))->toBeTrue()
        ->and($store->get($path))->toBe('minio-ok');

    $store->delete($path);
    expect($store->exists($path))->toBeFalse();
});

it('keeps Horizon on a non-reserved Redis connection and supervises the outbox queue', function () {
    expect(config('horizon.use'))->not->toBe('horizon')
        ->and(config('horizon.defaults.supervisor-core.connection'))->toBe('redis')
        ->and(config('horizon.defaults.supervisor-core.queue'))->toContain('default', 'outbox')
        ->and((int) config('horizon.defaults.supervisor-core.timeout'))->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});
