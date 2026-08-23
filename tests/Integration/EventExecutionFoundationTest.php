<?php

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Core\Application\Messaging\PublishOutboxMessage;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Contracts\OutboxTransport;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
use App\Modules\Events\Application\CanonicalEventPublished;
use App\Modules\Events\Application\CanonicalEventRecorder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed event execution tests.');
    }
});

it('publishes a typed canonical event only after its durable envelope validates', function () {
    Event::fake([CanonicalEventPublished::class]);
    $workspaceId = (string) Str::uuid();

    $event = app(CanonicalEventRecorder::class)->record(
        eventType: 'order.completed',
        workspaceId: $workspaceId,
        subjects: ['order_id' => 'order-42'],
        aggregateType: 'order',
        aggregateId: 'order-42',
        payload: ['total' => 12500],
        sourceMetadata: ['integration' => 'checkout'],
    );

    $stored = DB::table('outbox_messages')->where('id', $event->eventId)->first();
    $storedEnvelope = json_decode((string) $stored->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($storedEnvelope['event_id'])->toBe($event->eventId)
        ->and($storedEnvelope['schema_version'])->toBe(1)
        ->and($stored->published_at)->toBeNull();

    (new PublishOutboxMessage($event->eventId))->handle(
        app(OutboxRepository::class),
        app(OutboxTransport::class),
    );

    Event::assertDispatched(
        CanonicalEventPublished::class,
        static fn (CanonicalEventPublished $published): bool => $published->event->eventId === $event->eventId
            && $published->event->workspaceId === $workspaceId
    );
    expect(DB::table('outbox_messages')->where('id', $event->eventId)->value('published_at'))->not->toBeNull();
});

it('persists audit evidence for state-changing execution', function () {
    $workspaceId = (string) Str::uuid();
    $event = app(AuditRecorder::class)->record(
        workspaceId: $workspaceId,
        action: 'campaign.state.changed',
        evidence: ['from' => 'draft', 'to' => 'approved'],
        actorId: 'system:policy',
        subjectType: 'campaign',
        subjectId: 'campaign-9',
        correlationId: 'request-42',
    );

    $stored = DB::table('audit_events')->where('id', $event->id)->first();

    expect($stored)->not->toBeNull()
        ->and($stored->workspace_id)->toBe($workspaceId)
        ->and($stored->action)->toBe('campaign.state.changed')
        ->and(json_decode((string) $stored->evidence, true, 512, JSON_THROW_ON_ERROR))->toBe(['from' => 'draft', 'to' => 'approved']);
});

it('executes duplicate idempotency keys once and deterministically retries failed keys', function () {
    $workspaceId = (string) Str::uuid();
    $executor = app(IdempotentExecutor::class);
    $runs = 0;

    $first = $executor->run($workspaceId, 'campaign.schedule', 'schedule-42', function () use (&$runs): array {
        $runs++;

        return ['job_id' => 'job-42'];
    });
    $duplicate = $executor->run($workspaceId, 'campaign.schedule', 'schedule-42', function () use (&$runs): array {
        $runs++;

        return ['job_id' => 'duplicate'];
    });

    expect($first)->toBe(['job_id' => 'job-42'])
        ->and($duplicate)->toBe($first)
        ->and($runs)->toBe(1)
        ->and(DB::table('audit_events')->where('action', IdempotentExecutor::AUDIT_COMPLETED)->count())->toBe(1);

    expect(fn () => $executor->run($workspaceId, 'campaign.schedule', 'schedule-failure', static function (): array {
        throw new RuntimeException('transient execution failure');
    }))->toThrow(RuntimeException::class, 'transient execution failure');

    $retried = $executor->run($workspaceId, 'campaign.schedule', 'schedule-failure', static fn (): array => ['job_id' => 'job-recovered']);
    $row = DB::table('idempotency_keys')
        ->where('workspace_id', $workspaceId)
        ->where('scope', 'campaign.schedule')
        ->where('idempotency_key', 'schedule-failure')
        ->first();

    expect($retried)->toBe(['job_id' => 'job-recovered'])
        ->and($row->status)->toBe('completed')
        ->and((int) $row->attempts)->toBe(2)
        ->and(DB::table('audit_events')->where('action', IdempotentExecutor::AUDIT_FAILED)->count())->toBe(1);
});

it('dead letters terminal outbox failures and replays them without losing the durable message', function () {
    $clock = new class(new DateTimeImmutable('2026-08-23T12:00:00+00:00')) implements Clock {
        public function __construct(public DateTimeImmutable $current)
        {
        }

        public function now(): DateTimeImmutable
        {
            return $this->current;
        }

        public function advance(int $seconds): void
        {
            $this->current = $this->current->modify("+{$seconds} seconds");
        }
    };
    app()->instance(Clock::class, $clock);
    app()->forgetInstance(OutboxRepository::class);

    $event = app(CanonicalEventRecorder::class)->record(
        eventType: 'message.failed',
        workspaceId: (string) Str::uuid(),
        subjects: ['message_id' => 'message-42'],
        aggregateType: 'message',
        aggregateId: 'message-42',
        payload: ['reason' => 'transport'],
    );
    $outbox = app(OutboxRepository::class);
    $failingTransport = new class implements OutboxTransport {
        public function publish(OutboxMessage $message): void
        {
            throw new RuntimeException('transport unavailable');
        }
    };
    $job = new PublishOutboxMessage($event->eventId);

    for ($attempt = 1; $attempt <= PublishOutboxMessage::MAX_ATTEMPTS; $attempt++) {
        expect(fn () => $job->handle($outbox, $failingTransport))
            ->toThrow(RuntimeException::class, 'transport unavailable');

        if ($attempt < PublishOutboxMessage::MAX_ATTEMPTS) {
            $clock->advance(PublishOutboxMessage::BACKOFF_SECONDS[$attempt - 1]);
        }
    }

    $dead = DB::table('outbox_messages')->where('id', $event->eventId)->first();
    expect((int) $dead->attempts)->toBe(PublishOutboxMessage::MAX_ATTEMPTS)
        ->and($dead->dead_lettered_at)->not->toBeNull()
        ->and($dead->published_at)->toBeNull();

    expect(Artisan::call('outbox:replay', ['id' => $event->eventId]))->toBe(0);
    $replayed = DB::table('outbox_messages')->where('id', $event->eventId)->first();
    expect((int) $replayed->attempts)->toBe(0)
        ->and($replayed->dead_lettered_at)->toBeNull()
        ->and($outbox->findPending($event->eventId))->not->toBeNull();

    Event::fake([CanonicalEventPublished::class]);
    $job->handle($outbox, app(OutboxTransport::class));
    Event::assertDispatched(CanonicalEventPublished::class);
    expect(DB::table('outbox_messages')->where('id', $event->eventId)->value('published_at'))->not->toBeNull();
});
