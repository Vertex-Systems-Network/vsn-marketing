<?php

use App\Modules\Core\Application\Events\OutboxMessageReady;
use App\Modules\Core\Domain\Contracts\TransactionalOutbox;
use App\Modules\Core\Domain\ValueObjects\OutboxEnvelope;
use App\Modules\Core\Infrastructure\Outbox\OutboxRelay;
use App\Modules\Core\Infrastructure\Outbox\ProcessOutboxMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    Queue::connection('redis')->clear('outbox');
});

function taskFourEnvelope(): OutboxEnvelope
{
    $id = (string) Str::uuid();

    return new OutboxEnvelope(
        id: $id,
        topic: 'core.integration.probe',
        idempotencyKey: 'task-0004:'.$id,
        payload: ['probe' => true],
        occurredAt: new DateTimeImmutable('now'),
        headers: ['source' => 'integration'],
        aggregateType: 'probe',
        aggregateId: $id,
    );
}

it('refuses outbox writes outside the owning database transaction', function () {
    expect(fn () => app(TransactionalOutbox::class)->record(taskFourEnvelope()))
        ->toThrow(LogicException::class, 'active database transaction');
});

it('persists atomically then relays one message to Redis with an active lease', function () {
    $message = taskFourEnvelope();

    DB::transaction(fn () => app(TransactionalOutbox::class)->record($message));

    expect(DB::table('outbox_messages')->where('id', $message->id)->exists())->toBeTrue();

    $count = app(OutboxRelay::class)->relay();
    $row = DB::table('outbox_messages')->where('id', $message->id)->first();

    expect($count)->toBe(1)
        ->and((int) $row->relay_attempts)->toBe(1)
        ->and($row->locked_at)->not->toBeNull()
        ->and(Queue::connection('redis')->size('outbox'))->toBeGreaterThanOrEqual(1);
});

it('emits the canonical ready event before marking an outbox row published', function () {
    Event::fake([OutboxMessageReady::class]);
    $message = taskFourEnvelope();
    DB::transaction(fn () => app(TransactionalOutbox::class)->record($message));

    $job = new ProcessOutboxMessage($message->id);
    $job->handle(app('db'));

    Event::assertDispatched(OutboxMessageReady::class, fn ($event) => $event->id === $message->id && $event->idempotencyKey === $message->idempotencyKey);
    expect(DB::table('outbox_messages')->where('id', $message->id)->value('published_at'))->not->toBeNull();
});
