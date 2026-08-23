<?php

use App\Modules\Core\Application\Messaging\OutboxMessagePublished;
use App\Modules\Core\Application\Messaging\OutboxRecorder;
use App\Modules\Core\Application\Messaging\PublishOutboxMessage;
use App\Modules\Core\Domain\Contracts\DistributedLock;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Contracts\OutboxTransport;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->bind(DistributedLock::class, static fn () => new class implements DistributedLock
    {
        public function run(string $name, int $seconds, Closure $criticalSection): bool
        {
            $criticalSection();

            return true;
        }
    });
});

it('stores outbox records in the caller transaction and rolls them back together', function () {
    DB::beginTransaction();

    $id = app(OutboxRecorder::class)->record(
        topic: 'contact.created',
        aggregateType: 'contact',
        aggregateId: 'contact-1',
        payload: ['email' => 'test@example.com'],
    );

    expect(DB::table('outbox_messages')->where('id', $id)->exists())->toBeTrue();

    DB::rollBack();

    expect(DB::table('outbox_messages')->where('id', $id)->exists())->toBeFalse();
});

it('scans pending outbox rows without marking them published before a worker succeeds', function () {
    Queue::fake();

    $id = app(OutboxRecorder::class)->record(
        topic: 'contact.created',
        aggregateType: 'contact',
        aggregateId: 'contact-2',
        payload: ['email' => 'queued@example.com'],
    );

    expect(Artisan::call('outbox:dispatch', ['--limit' => 10]))->toBe(0);

    Queue::assertPushed(
        PublishOutboxMessage::class,
        static fn (PublishOutboxMessage $job): bool => $job->messageId === $id
    );

    expect(DB::table('outbox_messages')->where('id', $id)->value('published_at'))->toBeNull();
});

it('marks an outbox row published only after transport success', function () {
    Event::fake([OutboxMessagePublished::class]);

    $id = app(OutboxRecorder::class)->record(
        topic: 'order.completed',
        aggregateType: 'order',
        aggregateId: 'order-1',
        payload: ['total' => 12500],
    );

    app(PublishOutboxMessage::class, ['messageId' => $id])->handle(
        app(OutboxRepository::class),
        app(OutboxTransport::class),
    );

    Event::assertDispatched(OutboxMessagePublished::class);

    expect(DB::table('outbox_messages')->where('id', $id)->value('published_at'))->not->toBeNull();
});
