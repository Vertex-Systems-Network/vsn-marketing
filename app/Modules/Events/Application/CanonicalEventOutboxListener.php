<?php

namespace App\Modules\Events\Application;

use App\Modules\Core\Application\Messaging\OutboxMessagePublished;
use App\Modules\Events\Domain\CanonicalEvent;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

final readonly class CanonicalEventOutboxListener
{
    public function __construct(private Dispatcher $events) {}

    public function handle(OutboxMessagePublished $published): void
    {
        $message = $published->message;

        if (($message->headers['contract'] ?? null) !== CanonicalEvent::OUTBOX_CONTRACT) {
            return;
        }

        $event = CanonicalEvent::fromArray($message->payload);

        if ($event->eventId !== $message->id || $event->eventType !== $message->topic) {
            throw new RuntimeException('Canonical event envelope does not match its durable outbox identity.');
        }

        if ((int) ($message->headers['schema_version'] ?? 0) !== $event->schemaVersion) {
            throw new RuntimeException('Canonical event outbox header and envelope schema versions disagree.');
        }

        $this->events->dispatch(new CanonicalEventPublished($event));
    }
}
