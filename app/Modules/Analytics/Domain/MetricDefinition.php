<?php

namespace App\Modules\Analytics\Domain;

use InvalidArgumentException;

final readonly class MetricDefinition
{
    public const EVENTS = ['contact.created', 'product.viewed', 'cart.created', 'cart.abandoned',
        'order.created', 'order.completed', 'order.refunded', 'message.sent', 'message.delivered', 'message.opened',
        'message.clicked', 'message.bounced', 'message.complained', 'message.unsubscribed',
        'message.failed', 'journey.enrolled', 'journey.exited'];

    public function __construct(public string $eventType, public string $unit = 'event', public int $version = 1)
    {
        if (! in_array($eventType, self::EVENTS, true) || ! in_array($unit, ['event', 'subject'], true) || $version !== 1) {
            throw new InvalidArgumentException('Unsupported immutable analytics definition.');
        }
    }

    public function toArray(): array
    {
        return ['event_type' => $this->eventType, 'unit' => $this->unit, 'version' => $this->version,
            'timezone' => 'UTC', 'semantics' => $this->unit === 'subject' ? 'admitted_scoped_subject_count' : 'admitted_canonical_event_count', 'causal' => false];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
