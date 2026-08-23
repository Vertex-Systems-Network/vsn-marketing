<?php

use App\Modules\Core\Application\Messaging\OutboxRecorder;
use App\Modules\Core\Application\Messaging\PublishOutboxMessage;
use App\Modules\Core\Domain\Contracts\DistributedLock;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Contracts\OutboxTransport;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
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

it('persists publish failures and succeeds on a later retry', function () {
    $id = app(OutboxRecorder::class)->record(
        topic: 'integration.retry',
        aggregateType: 'probe',
        aggregateId: (string) Str::uuid(),
        payload: ['status' => 'retry'],
    );

    $job = new PublishOutboxMessage($id);

    expect($job->tries)->toBe(5)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->backoff())->toBe([5, 30, 120, 300]);

    $failingTransport = new class implements OutboxTransport {
        public function publish(OutboxMessage $message): void
        {
            throw new RuntimeException('transport unavailable');
        }
    };

    expect(fn () => $job->handle(app(OutboxRepository::class), $failingTransport))
        ->toThrow(RuntimeException::class, 'transport unavailable');

    $failed = DB::table('outbox_messages')->where('id', $id)->first();

    expect((int) $failed->attempts)->toBe(1)
        ->and($failed->last_error)->toBe('transport unavailable')
        ->and($failed->published_at)->toBeNull();

    $successfulTransport = new class implements OutboxTransport {
        public int $published = 0;

        public function publish(OutboxMessage $message): void
        {
            $this->published++;
        }
    };

    $job->handle(app(OutboxRepository::class), $successfulTransport);

    $published = DB::table('outbox_messages')->where('id', $id)->first();

    expect($successfulTransport->published)->toBe(1)
        ->and((int) $published->attempts)->toBe(1)
        ->and($published->last_error)->toBeNull()
        ->and($published->published_at)->not->toBeNull();
});
