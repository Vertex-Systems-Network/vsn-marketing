<?php

use App\Modules\Events\Domain\CanonicalEvent;
use DateTimeImmutable;
use InvalidArgumentException;

it('round trips the versioned canonical event envelope without changing identity', function () {
    $event = new CanonicalEvent(
        eventId: '01994ff8-5f60-7d94-bfa4-6910f15d9910',
        eventType: 'order.completed',
        occurredAt: new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-08-23T12:00:01+00:00'),
        workspaceId: '01994ff8-5f60-7d94-bfa4-6910f15d9911',
        brandId: null,
        subjects: ['order_id' => 'order-42'],
        source: 'internal',
        sourceEventId: null,
        schemaVersion: CanonicalEvent::SCHEMA_VERSION,
        payload: ['total' => 12500],
        sourceMetadata: ['integration' => 'checkout'],
    );

    $roundTrip = CanonicalEvent::fromArray($event->toArray());

    expect($roundTrip->eventId)->toBe($event->eventId)
        ->and($roundTrip->eventType)->toBe('order.completed')
        ->and($roundTrip->schemaVersion)->toBe(1)
        ->and($roundTrip->subjects)->toBe(['order_id' => 'order-42'])
        ->and($roundTrip->payload)->toBe(['total' => 12500]);
});

it('rejects unsupported schemas and secret-like source metadata', function () {
    $payload = (new CanonicalEvent(
        eventId: 'event-1',
        eventType: 'contact.updated',
        occurredAt: new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
        workspaceId: 'workspace-1',
        brandId: null,
        subjects: ['contact_id' => 'contact-1'],
        source: 'internal',
        sourceEventId: null,
        schemaVersion: 1,
        payload: [],
        sourceMetadata: [],
    ))->toArray();
    $payload['schema_version'] = 2;

    expect(fn () => CanonicalEvent::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, 'Unsupported canonical event schema version');

    expect(fn () => new CanonicalEvent(
        eventId: 'event-2',
        eventType: 'contact.updated',
        occurredAt: new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
        workspaceId: 'workspace-1',
        brandId: null,
        subjects: ['contact_id' => 'contact-1'],
        source: 'provider-x',
        sourceEventId: 'source-1',
        schemaVersion: 1,
        payload: [],
        sourceMetadata: ['authorization_token' => 'must-not-survive'],
    ))->toThrow(InvalidArgumentException::class, 'Sensitive source metadata key is forbidden');
});
