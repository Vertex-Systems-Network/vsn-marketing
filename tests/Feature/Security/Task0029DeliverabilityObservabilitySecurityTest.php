<?php

use App\Modules\DeliveryEngine\Application\Deliverability\BuildRemediationRecommendations;
use App\Modules\DeliveryEngine\Application\Deliverability\DeliverabilityDiagnosticResult;
use App\Modules\DeliveryEngine\Application\Deliverability\EvaluateDeliverabilityDiagnostics;
use App\Modules\DeliveryEngine\Application\Deliverability\RemediationRecommendation;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSafeSendingEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\SafeSendingEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Frequency\FrequencyEvaluationResult;
use App\Modules\DeliveryEngine\Application\Reputation\ReputationHealthEvaluationResult;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilitySignalKind;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDecision;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationReadiness;

function task0029SecurityAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-18T11:00:00+00:00');
}

function task0029SecurityObservation(
    string $id,
    string $signalValue = '0.001',
    string $workspaceId = 'workspace-a',
    string $providerKey = 'provider-a',
    bool $trusted = true,
    ?string $freshUntil = '2026-09-18T12:00:00+00:00',
    string $version = 'provider-a-2026-09-v1',
    string $observedAt = '2026-09-18T10:00:00+00:00',
): DeliverabilityObservation {
    return new DeliverabilityObservation(
        id: $id,
        workspaceId: $workspaceId,
        providerKey: $providerKey,
        source: 'provider-feedback-feed',
        version: $version,
        messagePurpose: 'marketing',
        kind: DeliverabilitySignalKind::Complaint,
        signalKey: 'complaint_rate',
        signalValue: $signalValue,
        provenanceReference: 'provider-doc://provider-a/feedback/2026-09',
        replayKey: 'replay-'.$id,
        effectiveAt: new DateTimeImmutable('2026-09-18T09:00:00+00:00'),
        observedAt: new DateTimeImmutable($observedAt),
        recordedAt: (new DateTimeImmutable($observedAt))->modify('+1 minute'),
        freshUntil: $freshUntil === null ? null : new DateTimeImmutable($freshUntil),
        trusted: $trusted,
    );
}

function task0029SecurityDiagnostics(array $observations): DeliverabilityDiagnosticResult
{
    return (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: $observations,
        evaluatedAt: task0029SecurityAt(),
    );
}

function task0029SecurityContext(
    PolicyBasisType $policyBasis = PolicyBasisType::ExplicitConsent,
    bool $policyBasisEvidencePresent = true,
    ?string $providerKey = 'provider-a',
    bool $providerContextKnown = true,
): EligibilityContext {
    return new EligibilityContext(
        messagePurpose: MessageIntentType::Marketing,
        jurisdiction: 'GB',
        subscriberType: 'individual',
        solicitationBasis: 'direct_marketing',
        relationshipBasis: 'existing_customer',
        policyBasis: $policyBasis,
        policyBasisEvidencePresent: $policyBasisEvidencePresent,
        jurisdictionPolicyOutcome: EligibilityOutcome::Allow,
        policyVersion: 'gb-pecr-2026-02',
        policyEffectiveAt: new DateTimeImmutable('2026-02-05T00:00:00+00:00'),
        providerKey: $providerKey,
        providerContextKnown: $providerContextKnown,
        suppressionApplies: false,
        objectionApplies: false,
    );
}

function task0029SecurityFrequency(EligibilityOutcome $outcome = EligibilityOutcome::Allow): FrequencyEvaluationResult
{
    return new FrequencyEvaluationResult(
        outcome: $outcome,
        reasons: [$outcome === EligibilityOutcome::Allow ? 'frequency_within_limit' : 'frequency_limit_reached'],
        evaluatedAt: task0029SecurityAt(),
        replay: false,
        currentCount: $outcome === EligibilityOutcome::Allow ? 0 : 1,
        nextCount: $outcome === EligibilityOutcome::Allow ? 1 : 1,
        windowStart: new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
        windowEnd: new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
    );
}

function task0029SecuritySafeRequest(
    bool $canonicalSuppression = false,
    EligibilityOutcome $frequencyOutcome = EligibilityOutcome::Allow,
    PolicyBasisType $policyBasis = PolicyBasisType::ExplicitConsent,
    bool $policyBasisEvidencePresent = true,
): SafeSendingEligibilityRequest {
    return new SafeSendingEligibilityRequest(
        workspaceId: 'workspace-a',
        suppressionAwareRequest: new SuppressionAwareEligibilityRequest(
            policyContext: task0029SecurityContext($policyBasis, $policyBasisEvidencePresent),
            canonicalSuppressionApplies: $canonicalSuppression,
            canonicalObjectionApplies: false,
        ),
        senderAuthentication: new AuthenticationDecision(
            workspaceId: 'workspace-a',
            senderDomainId: 'sender-domain-a',
            readiness: AuthenticationReadiness::Ready,
            reasons: ['authentication_evaluated'],
            evidenceVersions: ['spf-v1', 'dkim-v1', 'dmarc-v1'],
            decidedAt: task0029SecurityAt(),
        ),
        frequencyEvaluation: task0029SecurityFrequency($frequencyOutcome),
        reputationEvaluation: new ReputationHealthEvaluationResult(
            outcome: EligibilityOutcome::Allow,
            reasons: ['reputation_health_healthy'],
            evaluatedAt: task0029SecurityAt(),
            providerKey: 'provider-a',
            evidenceIds: ['healthy-evidence'],
        ),
        evaluatedAt: task0029SecurityAt(),
    );
}

function task0029SecuritySafeEvaluator(): EvaluateSafeSendingEligibility
{
    return new EvaluateSafeSendingEligibility(
        new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility),
    );
}

it('does not invent a universal provider threshold or convert raw metrics into sending permission', function () {
    $diagnostic = task0029SecurityDiagnostics([
        task0029SecurityObservation('extreme-raw-metric', signalValue: '99.999'),
    ]);
    $recommendation = (new BuildRemediationRecommendations)->build($diagnostic)[0];

    expect($diagnostic->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($diagnostic->reasons)->toContain('deliverability_evidence_observed')
        ->and($recommendation->code)->toBe('continue_provider_observation')
        ->and($recommendation->executionMode)->toBe(RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY)
        ->and($recommendation->rationale)->toContain('does not establish provider health or permission to send')
        ->and(method_exists($recommendation, 'execute'))->toBeFalse()
        ->and(property_exists($recommendation, 'allowed'))->toBeFalse();
});

it('fails closed on stale untrusted foreign-workspace and contradictory deliverability evidence', function () {
    $stale = task0029SecurityDiagnostics([
        task0029SecurityObservation(
            'stale',
            freshUntil: '2026-09-18T10:30:00+00:00',
        ),
    ]);
    $untrusted = task0029SecurityDiagnostics([
        task0029SecurityObservation('untrusted', trusted: false),
    ]);
    $foreign = task0029SecurityDiagnostics([
        task0029SecurityObservation('foreign', workspaceId: 'workspace-b'),
    ]);
    $contradictory = task0029SecurityDiagnostics([
        task0029SecurityObservation('contradiction-a', signalValue: '0.001'),
        task0029SecurityObservation('contradiction-b', signalValue: '0.900'),
    ]);

    expect($stale->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($stale->reasons)->toContain('deliverability_evidence_stale')
        ->and($untrusted->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($untrusted->reasons)->toContain('deliverability_evidence_untrusted')
        ->and($foreign->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($foreign->reasons)->toContain('deliverability_evidence_workspace_mismatch')
        ->and($contradictory->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($contradictory->reasons)->toContain('deliverability_evidence_contradictory');
});

it('keeps canonical suppression above healthy deliverability diagnostics and recommendations', function () {
    $diagnostic = task0029SecurityDiagnostics([
        task0029SecurityObservation('healthy-looking-observation'),
    ]);
    $recommendation = (new BuildRemediationRecommendations)->build($diagnostic)[0];
    $safeSending = task0029SecuritySafeEvaluator()->evaluate(
        task0029SecuritySafeRequest(canonicalSuppression: true),
    );

    expect($diagnostic->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($recommendation->code)->toBe('continue_provider_observation')
        ->and($safeSending->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($safeSending->suppressionAuthorityApplied)->toBeTrue()
        ->and($safeSending->reasons)->toContain('canonical_suppression_applies')
        ->and($safeSending->senderIdentityReady)->toBeFalse()
        ->and($safeSending->frequencyAllowed)->toBeFalse()
        ->and($safeSending->reputationHealthy)->toBeFalse();
});

it('does not let deliverability observations bypass an exhausted frequency cap', function () {
    $diagnostic = task0029SecurityDiagnostics([
        task0029SecurityObservation('frequency-bypass-attempt'),
    ]);
    $safeSending = task0029SecuritySafeEvaluator()->evaluate(
        task0029SecuritySafeRequest(frequencyOutcome: EligibilityOutcome::Deny),
    );

    expect($diagnostic->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($safeSending->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($safeSending->reasons)->toContain('frequency_limit_reached')
        ->and($safeSending->frequencyAllowed)->toBeFalse()
        ->and($safeSending->reputationHealthy)->toBeFalse();
});

it('does not let deliverability evidence recreate missing marketing authorization', function () {
    $diagnostic = task0029SecurityDiagnostics([
        task0029SecurityObservation('missing-authorization-attempt'),
    ]);
    $safeSending = task0029SecuritySafeEvaluator()->evaluate(
        task0029SecuritySafeRequest(
            policyBasis: PolicyBasisType::None,
            policyBasisEvidencePresent: false,
        ),
    );

    expect($diagnostic->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($safeSending->outcome)->toBe(EligibilityOutcome::Review)
        ->and($safeSending->reasons)->toContain('base_policy_outcome:review')
        ->and($safeSending->senderIdentityReady)->toBeFalse()
        ->and($safeSending->frequencyAllowed)->toBeFalse()
        ->and($safeSending->reputationHealthy)->toBeFalse();
});

it('rejects remediation codes that encode upstream authority bypass or provider evasion', function (string $code) {
    expect(fn () => new RemediationRecommendation(
        id: 'unsafe-'.$code,
        code: $code,
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        scope: 'evidence',
        rationale: 'This proposal must be rejected.',
        evidenceIds: [],
        riskLevel: RemediationRecommendation::RISK_LOW,
        requiresHumanApproval: false,
        requiresPolicyApproval: false,
        executionMode: RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY,
        recommendedAt: task0029SecurityAt(),
    ))->toThrow(InvalidArgumentException::class, 'Unsafe remediation recommendation code is forbidden');
})->with([
    'override_suppression',
    'bypass_frequency',
    'create_consent',
    'create_authorization',
    'rotate_account',
    'deceptive_header',
    'evade_provider',
    'circumvent_limit',
]);
