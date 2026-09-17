<?php

use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilitySignalKind;
use App\Modules\DeliveryEngine\Infrastructure\Deliverability\DeliverabilityObservationRepository;
use Illuminate\Auth\Access\AuthorizationException;

function task0029DeliverabilityObservation(
    string $id = 'observation-a',
    string $workspaceId = 'workspace-a',
    string $providerKey = 'provider-a',
    string $source = 'provider-feedback',
    string $version = 'provider-policy-v1',
    string $messagePurpose = 'marketing',
    DeliverabilitySignalKind $kind = DeliverabilitySignalKind::Complaint,
    string $signalKey = 'complaint_rate',
    string $signalValue = '0.0021',
    string $provenanceReference = 'provider-event-123',
    string $replayKey = 'provider-event-123',
    ?DateTimeImmutable $effectiveAt = null,
    ?DateTimeImmutable $observedAt = null,
    ?DateTimeImmutable $recordedAt = null,
    ?DateTimeImmutable $freshUntil = null,
    bool $trusted = true,
): DeliverabilityObservation {
    $effectiveAt ??= new DateTimeImmutable('2026-09-18T10:00:00+00:00');
    $observedAt ??= new DateTimeImmutable('2026-09-18T10:05:00+00:00');
    $recordedAt ??= new DateTimeImmutable('2026-09-18T10:06:00+00:00');
    $freshUntil ??= new DateTimeImmutable('2026-09-18T12:05:00+00:00');

    return new DeliverabilityObservation(
        id: $id,
        workspaceId: $workspaceId,
        providerKey: $providerKey,
        source: $source,
        version: $version,
        messagePurpose: $messagePurpose,
        kind: $kind,
        signalKey: $signalKey,
        signalValue: $signalValue,
        provenanceReference: $provenanceReference,
        replayKey: $replayKey,
        effectiveAt: $effectiveAt,
        observedAt: $observedAt,
        recordedAt: $recordedAt,
        freshUntil: $freshUntil,
        trusted: $trusted,
    );
}

it('preserves provider-versioned telemetry without turning it into permission', function () {
    $observation = task0029DeliverabilityObservation();

    expect($observation->workspaceId)->toBe('workspace-a')
        ->and($observation->providerKey)->toBe('provider-a')
        ->and($observation->source)->toBe('provider-feedback')
        ->and($observation->version)->toBe('provider-policy-v1')
        ->and($observation->messagePurpose)->toBe('marketing')
        ->and($observation->kind)->toBe(DeliverabilitySignalKind::Complaint)
        ->and($observation->signalKey)->toBe('complaint_rate')
        ->and($observation->signalValue)->toBe('0.0021')
        ->and($observation->provenanceReference)->toBe('provider-event-123');
});

it('represents all required signal dimensions without a universal health boolean', function () {
    expect(array_map(
        static fn (DeliverabilitySignalKind $kind): string => $kind->value,
        DeliverabilitySignalKind::cases(),
    ))->toBe([
        'sender_authentication',
        'complaint',
        'bounce',
        'delivery',
        'reputation',
        'health',
    ]);
});

it('records exact replays idempotently and rejects conflicting replay evidence', function () {
    $repository = new DeliverabilityObservationRepository;
    $observation = task0029DeliverabilityObservation();

    expect($repository->append($observation))->toBe($observation)
        ->and($repository->append(task0029DeliverabilityObservation()))->toBe($observation)
        ->and($repository->observations('workspace-a'))->toHaveCount(1);

    expect(fn () => $repository->append(task0029DeliverabilityObservation(signalValue: '0.9')))
        ->toThrow(InvalidArgumentException::class, 'replay key conflicts');
});

it('isolates workspace queries and fails closed on foreign-workspace id collisions', function () {
    $repository = new DeliverabilityObservationRepository;
    $repository->append(task0029DeliverabilityObservation());
    $repository->append(task0029DeliverabilityObservation(
        id: 'observation-b',
        workspaceId: 'workspace-b',
        replayKey: 'provider-event-456',
        provenanceReference: 'provider-event-456',
    ));

    expect($repository->observations('workspace-a'))->toHaveCount(1)
        ->and($repository->observations('workspace-b'))->toHaveCount(1)
        ->and($repository->observations('workspace-c'))->toBe([]);

    expect(fn () => $repository->append(task0029DeliverabilityObservation(
        workspaceId: 'workspace-b',
        replayKey: 'provider-event-foreign',
        provenanceReference: 'provider-event-foreign',
    )))->toThrow(AuthorizationException::class, 'access denied');
});

it('keeps stale and untrusted observations explicit instead of deleting or normalizing them', function () {
    $repository = new DeliverabilityObservationRepository;
    $stale = task0029DeliverabilityObservation(
        id: 'stale-a',
        replayKey: 'stale-a',
        provenanceReference: 'stale-a',
        freshUntil: new DateTimeImmutable('2026-09-18T10:10:00+00:00'),
        trusted: false,
    );

    $repository->append($stale);
    $stored = $repository->observations('workspace-a')[0];

    expect($stored->trusted)->toBeFalse()
        ->and($stored->isStaleAt(new DateTimeImmutable('2026-09-18T11:00:00+00:00')))->toBeTrue();
});

it('filters deterministically by provider and message purpose while preserving append order by evidence time', function () {
    $repository = new DeliverabilityObservationRepository;
    $repository->append(task0029DeliverabilityObservation(
        id: 'later',
        replayKey: 'later',
        provenanceReference: 'later',
        observedAt: new DateTimeImmutable('2026-09-18T10:30:00+00:00'),
        recordedAt: new DateTimeImmutable('2026-09-18T10:31:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-09-18T12:30:00+00:00'),
    ));
    $repository->append(task0029DeliverabilityObservation(
        id: 'earlier',
        replayKey: 'earlier',
        provenanceReference: 'earlier',
    ));
    $repository->append(task0029DeliverabilityObservation(
        id: 'transactional',
        messagePurpose: 'transactional',
        replayKey: 'transactional',
        provenanceReference: 'transactional',
    ));

    $marketing = $repository->observations('workspace-a', 'provider-a', 'marketing');

    expect(array_map(
        static fn (DeliverabilityObservation $observation): string => $observation->id,
        $marketing,
    ))->toBe(['earlier', 'later']);
});

it('rejects malformed provenance and impossible observation timelines', function () {
    expect(fn () => task0029DeliverabilityObservation(version: '   '))
        ->toThrow(InvalidArgumentException::class, 'version must be non-blank');

    expect(fn () => task0029DeliverabilityObservation(
        effectiveAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
        observedAt: new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
        recordedAt: new DateTimeImmutable('2026-09-18T11:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'observedAt must be on or after effectiveAt');
});
