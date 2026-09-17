<?php

use App\Modules\DeliveryEngine\Application\Reputation\EvaluateReputationHealth;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Reputation\ReputationHealthEvidence;
use App\Modules\DeliveryEngine\Domain\Reputation\ReputationHealthStatus;

function task0028ReputationEvidence(
    ReputationHealthStatus $status = ReputationHealthStatus::Healthy,
    string $id = 'evidence-a',
    string $workspaceId = 'workspace-a',
    string $providerKey = 'provider-a',
    string $source = 'provider-health-feed',
    string $version = 'provider-health-v1',
    bool $trusted = true,
    ?DateTimeImmutable $effectiveAt = null,
    ?DateTimeImmutable $observedAt = null,
    ?DateTimeImmutable $freshUntil = null,
): ReputationHealthEvidence {
    $effectiveAt ??= new DateTimeImmutable('2026-09-17T10:00:00+00:00');
    $observedAt ??= new DateTimeImmutable('2026-09-17T11:00:00+00:00');
    $freshUntil ??= new DateTimeImmutable('2026-09-17T13:00:00+00:00');

    return new ReputationHealthEvidence(
        id: $id,
        workspaceId: $workspaceId,
        providerKey: $providerKey,
        source: $source,
        version: $version,
        status: $status,
        effectiveAt: $effectiveAt,
        observedAt: $observedAt,
        freshUntil: $freshUntil,
        trusted: $trusted,
    );
}

it('never treats missing reputation evidence as implicit allow', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Unknown)
        ->and($result->reasons)->toContain('reputation_evidence_missing');
});

it('allows only current trusted healthy evidence for the requested workspace and provider', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [task0028ReputationEvidence()],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($result->reasons)->toContain('reputation_health_healthy')
        ->and($result->evidenceIds)->toBe(['evidence-a']);
});

it('fails closed on stale or untrusted evidence', function () {
    $at = new DateTimeImmutable('2026-09-17T14:00:00+00:00');
    $stale = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [task0028ReputationEvidence()],
        $at,
    );

    $untrusted = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [task0028ReputationEvidence(trusted: false)],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($stale->outcome)->toBe(EligibilityOutcome::Review)
        ->and($stale->reasons)->toContain('reputation_evidence_stale')
        ->and($untrusted->outcome)->toBe(EligibilityOutcome::Review)
        ->and($untrusted->reasons)->toContain('reputation_evidence_untrusted');
});

it('denies foreign-workspace evidence instead of reusing it', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [task0028ReputationEvidence(workspaceId: 'workspace-b')],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->reasons)->toContain('reputation_evidence_workspace_mismatch');
});

it('requires the provider context to match the versioned evidence', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-b',
        [task0028ReputationEvidence()],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->reasons)->toContain('reputation_evidence_provider_mismatch');
});

it('reviews contradictory current evidence rather than selecting the most permissive signal', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [
            task0028ReputationEvidence(ReputationHealthStatus::Healthy, id: 'healthy-a'),
            task0028ReputationEvidence(ReputationHealthStatus::Degraded, id: 'degraded-a', source: 'mailbox-feedback'),
        ],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->reasons)->toContain('reputation_evidence_contradictory');
});

it('denies when contradictory evidence includes a blocked signal', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [
            task0028ReputationEvidence(ReputationHealthStatus::Healthy, id: 'healthy-a'),
            task0028ReputationEvidence(ReputationHealthStatus::Blocked, id: 'blocked-a', source: 'mailbox-feedback'),
        ],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->reasons)->toContain('reputation_evidence_contradictory')
        ->and($result->reasons)->toContain('reputation_health_blocked');
});

it('fails closed when evidence is not yet effective', function () {
    $result = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [task0028ReputationEvidence(
            effectiveAt: new DateTimeImmutable('2026-09-17T13:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-09-17T13:00:00+00:00'),
            freshUntil: new DateTimeImmutable('2026-09-17T15:00:00+00:00'),
        )],
        new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->reasons)->toContain('reputation_evidence_not_yet_effective');
});
