<?php

use App\Modules\DeliveryEngine\Application\Deliverability\DeliverabilityDiagnosticResult;
use App\Modules\DeliveryEngine\Application\Deliverability\EvaluateDeliverabilityDiagnostics;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilitySignalKind;

function task0029DiagnosticObservation(
    string $id = 'diag-a',
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

it('produces an evidence-backed diagnostic without creating permission to send', function () {
    $observations = [];

    foreach (DeliverabilitySignalKind::cases() as $index => $kind) {
        $observations[] = task0029DiagnosticObservation(
            id: 'diag-'.$index,
            kind: $kind,
            signalKey: $kind->value.'_signal',
            signalValue: 'provider-value-'.$index,
            provenanceReference: 'provider-event-'.$index,
            replayKey: 'provider-event-'.$index,
        );
    }

    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: $observations,
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($result->reasons)->toContain('deliverability_evidence_observed')
        ->and($result->signalKinds)->toHaveCount(6)
        ->and(property_exists($result, 'allowed'))->toBeFalse()
        ->and(property_exists($result, 'outcome'))->toBeFalse();
});

it('keeps missing provider evidence unknown instead of fabricating health', function () {
    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: [],
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_UNKNOWN)
        ->and($result->reasons)->toBe(['deliverability_evidence_missing'])
        ->and($result->evidenceIds)->toBe([]);
});

it('routes untrusted stale and future evidence to review with stable reason codes', function () {
    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: [
            task0029DiagnosticObservation(
                id: 'untrusted',
                replayKey: 'untrusted',
                provenanceReference: 'untrusted',
                trusted: false,
            ),
            task0029DiagnosticObservation(
                id: 'stale',
                replayKey: 'stale',
                provenanceReference: 'stale',
                freshUntil: new DateTimeImmutable('2026-09-18T10:30:00+00:00'),
            ),
            task0029DiagnosticObservation(
                id: 'future',
                replayKey: 'future',
                provenanceReference: 'future',
                effectiveAt: new DateTimeImmutable('2026-09-18T11:30:00+00:00'),
                observedAt: new DateTimeImmutable('2026-09-18T11:35:00+00:00'),
                recordedAt: new DateTimeImmutable('2026-09-18T11:36:00+00:00'),
                freshUntil: new DateTimeImmutable('2026-09-18T12:35:00+00:00'),
            ),
        ],
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($result->reasons)->toContain('deliverability_evidence_untrusted')
        ->and($result->reasons)->toContain('deliverability_evidence_stale')
        ->and($result->reasons)->toContain('deliverability_evidence_not_yet_effective');
});

it('rejects foreign workspace provider and purpose context without normalizing the evidence', function () {
    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: [
            task0029DiagnosticObservation(
                id: 'foreign-workspace',
                workspaceId: 'workspace-b',
                replayKey: 'foreign-workspace',
                provenanceReference: 'foreign-workspace',
            ),
            task0029DiagnosticObservation(
                id: 'foreign-provider',
                providerKey: 'provider-b',
                replayKey: 'foreign-provider',
                provenanceReference: 'foreign-provider',
            ),
            task0029DiagnosticObservation(
                id: 'foreign-purpose',
                messagePurpose: 'transactional',
                replayKey: 'foreign-purpose',
                provenanceReference: 'foreign-purpose',
            ),
        ],
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($result->reasons)->toContain('deliverability_evidence_workspace_mismatch')
        ->and($result->reasons)->toContain('deliverability_evidence_provider_mismatch')
        ->and($result->reasons)->toContain('deliverability_evidence_message_purpose_mismatch');
});

it('detects contradictory evidence at the same provider-versioned observation point', function () {
    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: [
            task0029DiagnosticObservation(
                id: 'contradiction-a',
                source: 'provider-feedback-a',
                signalValue: '0.0021',
                replayKey: 'contradiction-a',
                provenanceReference: 'contradiction-a',
            ),
            task0029DiagnosticObservation(
                id: 'contradiction-b',
                source: 'provider-feedback-b',
                signalValue: '0.9000',
                replayKey: 'contradiction-b',
                provenanceReference: 'contradiction-b',
            ),
        ],
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($result->reasons)->toBe(['deliverability_evidence_contradictory']);
});

it('does not treat provider version changes as contradictory global thresholds', function () {
    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: [
            task0029DiagnosticObservation(
                id: 'version-a',
                version: 'provider-policy-v1',
                signalValue: '0.0021',
                replayKey: 'version-a',
                provenanceReference: 'version-a',
            ),
            task0029DiagnosticObservation(
                id: 'version-b',
                version: 'provider-policy-v2',
                signalValue: '0.9000',
                replayKey: 'version-b',
                provenanceReference: 'version-b',
            ),
        ],
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($result->reasons)->toContain('deliverability_signal_observed:complaint');
});

it('returns deterministic diagnostics regardless of evidence order', function () {
    $observations = [
        task0029DiagnosticObservation(
            id: 'bounce',
            kind: DeliverabilitySignalKind::Bounce,
            signalKey: 'bounce_rate',
            replayKey: 'bounce',
            provenanceReference: 'bounce',
        ),
        task0029DiagnosticObservation(
            id: 'complaint',
            kind: DeliverabilitySignalKind::Complaint,
            replayKey: 'complaint',
            provenanceReference: 'complaint',
        ),
    ];

    $evaluator = new EvaluateDeliverabilityDiagnostics;
    $first = $evaluator->evaluate(
        'workspace-a',
        'provider-a',
        'marketing',
        $observations,
        new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );
    $second = $evaluator->evaluate(
        'workspace-a',
        'provider-a',
        'marketing',
        array_reverse($observations),
        new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($first->status)->toBe($second->status)
        ->and($first->reasons)->toBe($second->reasons)
        ->and($first->evidenceIds)->toBe($second->evidenceIds)
        ->and($first->signalKinds)->toBe($second->signalKinds);
});

it('routes malformed evidence to review instead of coercing it', function () {
    $result = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: ['not-an-observation'],
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect($result->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($result->reasons)->toBe(['deliverability_evidence_malformed']);
});
