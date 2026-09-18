<?php

use App\Modules\Consent\Application\Unsubscribe\AcceptOneClickUnsubscribe;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\Rfc8058Eligibility;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
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
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicy;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicyEvaluator;
use App\Modules\Providers\Domain\SenderPolicy\ProviderVolumeClassification;

function task0030SecurityAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-18T12:00:00+00:00');
}

function task0030SecurityContext(
    PolicyBasisType $basis = PolicyBasisType::ExplicitConsent,
    bool $basisEvidence = true,
    ?string $providerKey = 'provider-a',
    bool $providerKnown = true,
): EligibilityContext {
    return new EligibilityContext(
        messagePurpose: MessageIntentType::Marketing,
        jurisdiction: 'GB',
        subscriberType: 'individual',
        solicitationBasis: 'direct_marketing',
        relationshipBasis: 'existing_customer',
        policyBasis: $basis,
        policyBasisEvidencePresent: $basisEvidence,
        jurisdictionPolicyOutcome: EligibilityOutcome::Allow,
        policyVersion: 'gb-pecr-2026-02',
        policyEffectiveAt: new DateTimeImmutable('2026-02-05T00:00:00+00:00'),
        providerKey: $providerKey,
        providerContextKnown: $providerKnown,
        suppressionApplies: false,
        objectionApplies: false,
    );
}

function task0030SecurityFrequency(EligibilityOutcome $outcome = EligibilityOutcome::Allow): FrequencyEvaluationResult
{
    return new FrequencyEvaluationResult(
        outcome: $outcome,
        reasons: [$outcome === EligibilityOutcome::Allow ? 'frequency_within_limit' : 'frequency_limit_reached'],
        evaluatedAt: task0030SecurityAt(),
        replay: false,
        currentCount: $outcome === EligibilityOutcome::Allow ? 0 : 1,
        nextCount: 1,
        windowStart: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
        windowEnd: new DateTimeImmutable('2026-09-18T13:00:00+00:00'),
    );
}

function task0030SecurityReputation(
    EligibilityOutcome $outcome = EligibilityOutcome::Allow,
    string $providerKey = 'provider-a',
): ReputationHealthEvaluationResult {
    return new ReputationHealthEvaluationResult(
        outcome: $outcome,
        reasons: [$outcome === EligibilityOutcome::Allow ? 'reputation_health_healthy' : 'reputation_health_blocked'],
        evaluatedAt: task0030SecurityAt(),
        providerKey: $providerKey,
        evidenceIds: ['task0030-reputation-evidence'],
    );
}

function task0030SecurityAuthentication(
    AuthenticationReadiness $readiness = AuthenticationReadiness::Ready,
    string $workspaceId = 'workspace-a',
): AuthenticationDecision {
    return new AuthenticationDecision(
        workspaceId: $workspaceId,
        senderDomainId: 'sender-domain-a',
        readiness: $readiness,
        reasons: ['task0030_authentication_evaluated'],
        evidenceVersions: ['spf-v1', 'dkim-v1', 'dmarc-v1'],
        decidedAt: task0030SecurityAt(),
    );
}

function task0030SecuritySafeEvaluator(): EvaluateSafeSendingEligibility
{
    return new EvaluateSafeSendingEligibility(
        new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility),
    );
}

function task0030SecuritySafeRequest(
    bool $suppressed = false,
    bool $objected = false,
    ?EligibilityContext $context = null,
    ?AuthenticationDecision $authentication = null,
    ?FrequencyEvaluationResult $frequency = null,
    ?ReputationHealthEvaluationResult $reputation = null,
): SafeSendingEligibilityRequest {
    return new SafeSendingEligibilityRequest(
        workspaceId: 'workspace-a',
        suppressionAwareRequest: new SuppressionAwareEligibilityRequest(
            policyContext: $context ?? task0030SecurityContext(),
            canonicalSuppressionApplies: $suppressed,
            canonicalObjectionApplies: $objected,
        ),
        senderAuthentication: $authentication ?? task0030SecurityAuthentication(),
        frequencyEvaluation: $frequency ?? task0030SecurityFrequency(),
        reputationEvaluation: $reputation ?? task0030SecurityReputation(),
        evaluatedAt: task0030SecurityAt(),
    );
}

function task0030SecurityObservation(
    string $id,
    string $signalValue = '0.001',
    string $workspaceId = 'workspace-a',
    string $providerKey = 'provider-a',
    string $version = 'provider-a-2026-09-v1',
    bool $trusted = true,
    ?string $freshUntil = '2026-09-18T13:00:00+00:00',
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
        effectiveAt: new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
        observedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
        recordedAt: new DateTimeImmutable('2026-09-18T11:01:00+00:00'),
        freshUntil: $freshUntil === null ? null : new DateTimeImmutable($freshUntil),
        trusted: $trusted,
    );
}

it('proves canonical suppression and objection cannot be bypassed by healthy downstream signals or deliverability recommendations', function (bool $suppressed, bool $objected, string $reason) {
    $diagnostic = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        observations: [task0030SecurityObservation('healthy-looking-signal', signalValue: '0.0001')],
        evaluatedAt: task0030SecurityAt(),
    );
    $recommendation = (new BuildRemediationRecommendations)->build($diagnostic)[0];

    $result = task0030SecuritySafeEvaluator()->evaluate(
        task0030SecuritySafeRequest(suppressed: $suppressed, objected: $objected),
    );

    expect($diagnostic->status)->toBe(DeliverabilityDiagnosticResult::STATUS_OBSERVED)
        ->and($recommendation->executionMode)->toBe(RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY)
        ->and(method_exists($recommendation, 'execute'))->toBeFalse()
        ->and($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->suppressionAuthorityApplied)->toBeTrue()
        ->and($result->senderIdentityReady)->toBeFalse()
        ->and($result->frequencyAllowed)->toBeFalse()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain($reason);
})->with([
    'suppression' => [true, false, 'canonical_suppression_applies'],
    'objection' => [false, true, 'canonical_objection_applies'],
]);

it('keeps missing authorization and missing provider context fail closed despite healthy sender signals', function () {
    $missingAuthorization = task0030SecuritySafeEvaluator()->evaluate(
        task0030SecuritySafeRequest(context: task0030SecurityContext(
            basis: PolicyBasisType::None,
            basisEvidence: false,
        )),
    );

    $missingProvider = task0030SecuritySafeEvaluator()->evaluate(
        task0030SecuritySafeRequest(context: task0030SecurityContext(
            providerKey: null,
            providerKnown: false,
        )),
    );

    expect($missingAuthorization->outcome)->toBe(EligibilityOutcome::Review)
        ->and($missingAuthorization->senderIdentityReady)->toBeFalse()
        ->and($missingAuthorization->reasons)->toContain('base_policy_outcome:review')
        ->and($missingProvider->outcome)->toBe(EligibilityOutcome::Unknown)
        ->and($missingProvider->senderIdentityReady)->toBeFalse()
        ->and($missingProvider->reasons)->toContain('base_policy_outcome:unknown');
});

it('keeps an exhausted frequency cap above alternate-provider reputation signals', function () {
    $result = task0030SecuritySafeEvaluator()->evaluate(task0030SecuritySafeRequest(
        frequency: task0030SecurityFrequency(EligibilityOutcome::Deny),
        reputation: task0030SecurityReputation(providerKey: 'provider-b'),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->senderIdentityReady)->toBeTrue()
        ->and($result->frequencyAllowed)->toBeFalse()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain('frequency_limit_reached');
});

it('certifies RFC8058 one-click semantics are marketing scoped DKIM covered and replay safe', function () {
    $scope = new UnsubscribeScope(
        workspaceId: 'workspace-a',
        contactId: 'contact-a',
        channel: 'email',
        purpose: 'marketing',
        scopeType: 'list',
        scopeKey: 'weekly',
    );
    $token = new OpaqueUnsubscribeToken(
        value: str_repeat('u', 43),
        scope: $scope,
        issuedAt: new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
        expiresAt: new DateTimeImmutable('2026-10-18T10:00:00+00:00'),
    );

    expect((new Rfc8058Eligibility(
        messagePurpose: MessageIntentType::Marketing,
        dkimSignatureValid: true,
        dkimCoveredHeaders: ['From', 'List-Unsubscribe', 'List-Unsubscribe-Post'],
    ))->eligible())->toBeTrue()
        ->and((new Rfc8058Eligibility(
            messagePurpose: MessageIntentType::Transactional,
            dkimSignatureValid: true,
            dkimCoveredHeaders: ['List-Unsubscribe', 'List-Unsubscribe-Post'],
        ))->eligible())->toBeFalse();

    $handler = new AcceptOneClickUnsubscribe;
    $at = new DateTimeImmutable('2026-09-18T10:05:00+00:00');
    $first = $handler->handle($token, 'POST', 'List-Unsubscribe=One-Click', $at);
    $replay = $handler->handle($token, 'POST', 'List-Unsubscribe=One-Click', $at);

    expect($replay)->toEqual($first)
        ->and($first->idempotencyKey)->toBe('rfc8058:'.$token->digest)
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain($token->value);

    expect(fn () => $handler->handle($token, 'GET', 'List-Unsubscribe=One-Click', $at))
        ->toThrow(InvalidArgumentException::class, 'requires POST');
});

it('keeps provider bulk semantics versioned and refuses to invent a universal numeric threshold', function () {
    $at = task0030SecurityAt();
    $policy = new MailboxProviderPolicy(
        id: 'provider-policy-no-global-threshold',
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        policyKey: 'bulk-sender',
        policyVersion: '2026-09',
        effectiveFrom: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        effectiveUntil: null,
        highVolumeThreshold: null,
        thresholdUnit: null,
        classificationInputs: ['source' => 'official'],
        requirements: ['dmarc' => true],
        provenanceUrl: 'https://example.test/provider-policy',
        sourceVersion: 'source-2026-09',
        observedAt: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-10T00:00:00+00:00'),
    );

    $decision = (new MailboxProviderPolicyEvaluator)->decide(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        policies: [$policy],
        at: $at,
    );

    expect($decision->classification)->toBe(ProviderVolumeClassification::ConfigurationRequired)
        ->and($decision->policyVersion)->toBe('2026-09')
        ->and($decision->reasons)->toContain('provider_does_not_publish_universal_numeric_threshold');

    $stale = new MailboxProviderPolicy(
        id: 'provider-policy-stale',
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        policyKey: 'bulk-sender',
        policyVersion: '2026-08',
        effectiveFrom: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        effectiveUntil: null,
        highVolumeThreshold: 5000,
        thresholdUnit: 'messages_per_day',
        classificationInputs: ['source' => 'official'],
        requirements: ['dmarc' => true],
        provenanceUrl: 'https://example.test/provider-policy-stale',
        sourceVersion: 'source-2026-08',
        observedAt: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
    );
    $staleDecision = (new MailboxProviderPolicyEvaluator)->decide(
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        policies: [$stale],
        at: $at,
        observedVolume: 9000,
    );

    expect($staleDecision->classification)->toBe(ProviderVolumeClassification::Unknown)
        ->and($staleDecision->reasons)->toContain('stale_or_unknown_policy_freshness');
});

it('keeps stale foreign untrusted and contradictory deliverability telemetry in review', function () {
    $evaluator = new EvaluateDeliverabilityDiagnostics;

    $stale = $evaluator->evaluate(
        'workspace-a',
        'provider-a',
        'marketing',
        [task0030SecurityObservation('stale', freshUntil: '2026-09-18T11:30:00+00:00')],
        task0030SecurityAt(),
    );
    $foreign = $evaluator->evaluate(
        'workspace-a',
        'provider-a',
        'marketing',
        [task0030SecurityObservation('foreign', workspaceId: 'workspace-b')],
        task0030SecurityAt(),
    );
    $untrusted = $evaluator->evaluate(
        'workspace-a',
        'provider-a',
        'marketing',
        [task0030SecurityObservation('untrusted', trusted: false)],
        task0030SecurityAt(),
    );
    $contradictory = $evaluator->evaluate(
        'workspace-a',
        'provider-a',
        'marketing',
        [
            task0030SecurityObservation('contradiction-a', signalValue: '0.001'),
            task0030SecurityObservation('contradiction-b', signalValue: '0.999'),
        ],
        task0030SecurityAt(),
    );

    expect($stale->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($stale->reasons)->toContain('deliverability_evidence_stale')
        ->and($foreign->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($foreign->reasons)->toContain('deliverability_evidence_workspace_mismatch')
        ->and($untrusted->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($untrusted->reasons)->toContain('deliverability_evidence_untrusted')
        ->and($contradictory->status)->toBe(DeliverabilityDiagnosticResult::STATUS_REVIEW)
        ->and($contradictory->reasons)->toContain('deliverability_evidence_contradictory');

    foreach ([$stale, $foreign, $untrusted, $contradictory] as $diagnostic) {
        $recommendation = (new BuildRemediationRecommendations)->build($diagnostic)[0];
        expect($recommendation->executionMode)->toBe(RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY)
            ->and($recommendation->code)->toBe('investigate_deliverability_evidence');
    }
});

it('rejects remediation language that encodes suppression frequency consent or provider-control evasion', function (string $code) {
    expect(fn () => new RemediationRecommendation(
        id: 'task0030-'.$code,
        code: $code,
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        scope: 'monitoring',
        rationale: 'Unsafe recommendation must be rejected.',
        evidenceIds: [],
        riskLevel: RemediationRecommendation::RISK_LOW,
        requiresHumanApproval: false,
        requiresPolicyApproval: false,
        executionMode: RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY,
        recommendedAt: task0030SecurityAt(),
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
