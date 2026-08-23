<?php

use App\Modules\Core\Application\Messaging\OutboxRecorder;
use App\Modules\Core\Domain\Contracts\DistributedLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed infrastructure tests.');
    }
});

it('runs migrations against PostgreSQL 18', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $version = (string) DB::selectOne('show server_version')->server_version;

    expect($version)->toStartWith('18.')
        ->and(DB::getSchemaBuilder()->hasTable('outbox_messages'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasTable('failed_jobs'))->toBeTrue();
});

it('uses isolated Redis cache and distributed lock connections', function () {
    $key = 'infra:'.Str::uuid();
    Cache::store('redis')->put($key, 'ready', 30);

    expect(Cache::store('redis')->get($key))->toBe('ready');

    $ran = false;
    $locked = app(DistributedLock::class)->run($key.':lock', 10, static function () use (&$ran): void {
        $ran = true;
    });

    expect($locked)->toBeTrue()->and($ran)->toBeTrue();

    Cache::store('redis')->forget($key);
});

it('loads Horizon and enqueues durable outbox work on Redis without pre-publishing it', function () {
    $queue = 'outbox-integration-'.Str::lower(Str::random(12));
    config()->set('infrastructure.outbox.queue', $queue);

    expect(class_exists(\Laravel\Horizon\Horizon::class))->toBeTrue()
        ->and(config('horizon.defaults.supervisor-1.queue'))->toContain('default', 'outbox');

    $id = app(OutboxRecorder::class)->record(
        topic: 'integration.probe',
        aggregateType: 'probe',
        aggregateId: (string) Str::uuid(),
        payload: ['status' => 'pending'],
    );

    $before = Queue::connection('redis')->size($queue);

    expect(Artisan::call('outbox:dispatch', ['--limit' => 10]))->toBe(0)
        ->and(Queue::connection('redis')->size($queue))->toBe($before + 1)
        ->and(DB::table('outbox_messages')->where('id', $id)->value('published_at'))->toBeNull();
});
