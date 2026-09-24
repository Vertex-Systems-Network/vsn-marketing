<?php

use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Publishing\Domain\Publication\PublicationPolicyBoundaryEvidence;
use App\Modules\Publishing\Domain\Publication\PublicationProviderCircuitState;
use App\Modules\Publishing\Domain\Publication\PublicationProviderOutcome;
use App\Modules\Publishing\Domain\Publication\PublicationProviderOutcomeCode;
use DateTimeImmutable;
use InvalidArgumentException;

it('orders policy-boundary failures deterministically and hashes the evidence', function () {
    $evidence = new PublicationPolicyBoundaryEvidence(
        workspaceAllowed: true,
        approvalValid: false,
        consentSuppressionAllowed: false,
        senderContentAllowed: true,
        assetAllowed: true,
        providerPolicyAllowed: true,
    );

    expect($evidence->allPass())->toBeFalse()
        ->and($evidence->firstFailure())->toBe('approval_valid')
        ->and(strlen($evidence->evidenceHash))->toBe(64);
});

it('never permits a provider outcome to authorize fallback', function () {
    expect(fn () => new PublicationProviderOutcome(
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        authorizationHash: str_repeat('a', 64),
        code: PublicationProviderOutcomeCode::Ready,
        circuitState: PublicationProviderCircuitState::Closed,
        policyBoundaryEvidenceHash: str_repeat('b', 64),
        failedPolicyBoundary: null,
        providerErrorCategory: null,
        retryAfterSeconds: null,
        currentCapabilityEvidenceId: 'capability-1',
        currentCapabilitySourceVersion: '2026-09',
        retryEligible: false,
        workHeld: false,
        fallbackAllowed: true,
        evaluatedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'never authorize policy-bypassing fallback');
});

it('permits retry eligibility only for explicit retryable provider outcomes', function () {
    expect(fn () => new PublicationProviderOutcome(
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        authorizationHash: str_repeat('a', 64),
        code: PublicationProviderOutcomeCode::ProviderDisconnected,
        circuitState: PublicationProviderCircuitState::Closed,
        policyBoundaryEvidenceHash: str_repeat('b', 64),
        failedPolicyBoundary: null,
        providerErrorCategory: ProviderErrorCategory::Unavailable,
        retryAfterSeconds: null,
        currentCapabilityEvidenceId: null,
        currentCapabilitySourceVersion: null,
        retryEligible: true,
        workHeld: true,
        fallbackAllowed: false,
        evaluatedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'explicit retryable provider outcomes');
});

it('keeps canonical provider outcome payload free of credential material', function () {
    $outcome = new PublicationProviderOutcome(
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        authorizationHash: str_repeat('a', 64),
        code: PublicationProviderOutcomeCode::RateLimited,
        circuitState: PublicationProviderCircuitState::Closed,
        policyBoundaryEvidenceHash: str_repeat('b', 64),
        failedPolicyBoundary: null,
        providerErrorCategory: ProviderErrorCategory::RateLimited,
        retryAfterSeconds: 30,
        currentCapabilityEvidenceId: 'capability-1',
        currentCapabilitySourceVersion: '2026-09',
        retryEligible: true,
        workHeld: true,
        fallbackAllowed: false,
        evaluatedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );
    $encoded = json_encode($outcome->canonicalPayload(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('secret')
        ->and($encoded)->not->toContain('token')
        ->and($encoded)->not->toContain('credential')
        ->and(strlen($outcome->outcomeHash))->toBe(64);
});
