<?php

use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSafeSendingEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\SafeSendingEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Frequency\EvaluateFrequencyPolicy;
use App\Modules\DeliveryEngine\Application\Frequency\FrequencyEvaluationResult;
use App\Modules\DeliveryEngine\Application\Reputation\EvaluateReputationHealth;
use App\Modules\DeliveryEngine\Application\Reputation\ReputationHealthEvaluationResult;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyCounterSnapshot;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyPolicy;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\Reputation\ReputationHealthEvidence;
use App\Modules\DeliveryEngine\Domain\Reputation\ReputationHealthStatus;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDecision;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationReadiness;

function task0028SecurityCertAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-17T12:15:00+00:00');
}

function task0028SecurityCertContext(
    MessageIntentType $purpose = MessageIntentType::Marketing,
    PolicyBasisType $policyBasis = PolicyBasisType::ExplicitConsent,
    bool $basisEvidence = true,
    ?string $providerKey = 'provider-a',
    bool $providerKnown = true,
): EligibilityContext {
    return new EligibilityContext(
        messagePurpose: $purpose,
        jurisdiction: 'GB',
        subscriberType: 'individual',
        solicitationBasis: $purpose === MessageIntentType::Marketing ? 'direct_marketing' : 'service_delivery',
        relationshipBasis: 'existing_customer',
        policyBasis: $policyBasis,
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

function task0028SecurityCertSuppressionRequest(
    ?EligibilityContext $context = null,
    bool $canonicalSuppression = false,
    bool $canonicalObjection = false,
): SuppressionAwareEligibilityRequest {
    return new SuppressionAwareEligibilityRequest(
        policyContext: $context ?? task0028SecurityCertContext(),
        canonicalSuppressionApplies: $canonicalSuppression,
        canonicalObjectionApplies: $canonicalObjection,
    );
}

function task0028SecurityCertAuthentication(
    string $workspaceId = 'workspace-a',
    AuthenticationReadiness $readiness = AuthenticationReadiness::Ready,
): AuthenticationDecision {
    return new AuthenticationDecision(
        workspaceId: $workspaceId,
        senderDomainId: 'sender-domain-a',
        readiness: $readiness,
        reasons: ['authentication_evaluated'],
        evidenceVersions: ['spf-v1', 'dkim-v1', 'dmarc-v1'],
        decidedAt: task0028SecurityCertAt(),
    );
}

function task0028SecurityCertFrequencyAllow(): FrequencyEvaluationResult
{
    $at = task0028SecurityCertAt();

    return new FrequencyEvaluationResult(
        outcome: EligibilityOutcome::Allow,
        reasons: ['frequency_within_limit'],
        evaluatedAt: $at,
        replay: false,
        currentCount: 0,
        nextCount: 1,
        windowStart: new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
        windowEnd: new DateTimeImmutable('2026-09-17T13:00:00+00:00'),
    );
}

function task0028SecurityCertReputationAllow(string $providerKey = 'provider-a'): ReputationHealthEvaluationResult
{
    return new ReputationHealthEvaluationResult(
        outcome: EligibilityOutcome::Allow,
        reasons: ['reputation_health_healthy'],
        evaluatedAt: task0028SecurityCertAt(),
        providerKey: $providerKey,
        evidenceIds: ['healthy-evidence'],
    );
}

function task0028SecurityCertEvaluator(): EvaluateSafeSendingEligibility
{
    return new EvaluateSafeSendingEligibility(
        new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility),
    );
}

function task0028SecurityCertRequest(
    ?SuppressionAwareEligibilityRequest $suppressionRequest = null,
    ?AuthenticationDecision $authentication = null,
    ?FrequencyEvaluationResult $frequency = null,
    ?ReputationHealthEvaluationResult $reputation = null,
    string $workspaceId = 'workspace-a',
): SafeSendingEligibilityRequest {
    return new SafeSendingEligibilityRequest(
        workspaceId: $workspaceId,
        suppressionAwareRequest: $suppressionRequest ?? task0028SecurityCertSuppressionRequest(),
        senderAuthentication: $authentication ?? task0028SecurityCertAuthentication($workspaceId),
        frequencyEvaluation: $frequency ?? task0028SecurityCertFrequencyAllow(),
        reputationEvaluation: $reputation ?? task0028SecurityCertReputationAllow(),
        evaluatedAt: task0028SecurityCertAt(),
    );
}

function task0028SecurityCertFrequencyDenied(string $operationKey = 'new-operation'): FrequencyEvaluationResult
{
    $at = task0028SecurityCertAt();
    $policy = new FrequencyPolicy(
        id: 'security-cap-policy',
        workspaceId: 'workspace-a',
        messagePurpose: MessageIntentType::Marketing,
        recipientScope: 'recipient-a',
        windowSeconds: 3600,
        maxMessages: 1,
        version: 'security-cap-v1',
        effectiveAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
    );
    $window = $policy->windowFor($at);
    $counter = new FrequencyCounterSnapshot(
        workspaceId: 'workspace-a',
        policyId: $policy->id,
        recipientScope: $policy->recipientScope,
        windowStart: $window['start'],
        windowEnd: $window['end'],
        count: 1,
        countedOperationKeys: ['already-counted-operation'],
        observedAt: $at,
    );

    return (new EvaluateFrequencyPolicy)->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-a',
        $operationKey,
        $policy,
        $counter,
        $at,
    );
}

function task0028SecurityCertReputationEvidence(
    ReputationHealthStatus $status,
    string $id,
    string $workspaceId = 'workspace-a',
    string $providerKey = 'provider-a',
    ?DateTimeImmutable $freshUntil = null,
    string $source = 'provider-health-feed',
): ReputationHealthEvidence {
    return new ReputationHealthEvidence(
        id: $id,
        workspaceId: $workspaceId,
        providerKey: $providerKey,
        source: $source,
        version: 'security-health-v1',
        status: $status,
        effectiveAt: new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        observedAt: new DateTimeImmutable('2026-09-17T11:00:00+00:00'),
        freshUntil: $freshUntil ?? new DateTimeImmutable('2026-09-17T13:00:00+00:00'),
        trusted: true,
    );
}

it('keeps canonical suppression and objection above every healthy downstream signal', function () {
    $suppressed = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        suppressionRequest: task0028SecurityCertSuppressionRequest(canonicalSuppression: true),
    ));
    $objected = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        suppressionRequest: task0028SecurityCertSuppressionRequest(canonicalObjection: true),
    ));

    expect($suppressed->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($suppressed->suppressionAuthorityApplied)->toBeTrue()
        ->and($suppressed->senderIdentityReady)->toBeFalse()
        ->and($suppressed->frequencyAllowed)->toBeFalse()
        ->and($suppressed->reputationHealthy)->toBeFalse()
        ->and($suppressed->reasons)->toContain('canonical_suppression_applies')
        ->and($objected->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($objected->reasons)->toContain('canonical_objection_applies');
});

it('does not let provider failover bypass an exhausted frequency cap', function () {
    $frequency = task0028SecurityCertFrequencyDenied();

    expect($frequency->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($frequency->reasons)->toContain('frequency_limit_reached');

    $result = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        frequency: $frequency,
        reputation: task0028SecurityCertReputationAllow('provider-b'),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->senderIdentityReady)->toBeTrue()
        ->and($result->frequencyAllowed)->toBeFalse()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain('frequency_limit_reached');
});

it('reviews a provider-switch attempt even when the alternate provider reports healthy', function () {
    $result = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        reputation: task0028SecurityCertReputationAllow('provider-b'),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->frequencyAllowed)->toBeTrue()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain('reputation_provider_context_mismatch');
});

it('fails closed on stale and contradictory provider health instead of selecting the permissive signal', function () {
    $reputationEvaluator = new EvaluateReputationHealth;
    $at = task0028SecurityCertAt();
    $stale = $reputationEvaluator->evaluate(
        'workspace-a',
        'provider-a',
        [task0028SecurityCertReputationEvidence(
            ReputationHealthStatus::Healthy,
            'stale-healthy',
            freshUntil: new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
        )],
        $at,
    );
    $contradictoryBlocked = $reputationEvaluator->evaluate(
        'workspace-a',
        'provider-a',
        [
            task0028SecurityCertReputationEvidence(ReputationHealthStatus::Healthy, 'healthy-a'),
            task0028SecurityCertReputationEvidence(
                ReputationHealthStatus::Blocked,
                'blocked-a',
                source: 'mailbox-feedback',
            ),
        ],
        $at,
    );

    $staleResult = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(reputation: $stale));
    $blockedResult = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        reputation: $contradictoryBlocked,
    ));

    expect($staleResult->outcome)->toBe(EligibilityOutcome::Review)
        ->and($staleResult->reasons)->toContain('reputation_evidence_stale')
        ->and($blockedResult->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($blockedResult->reasons)->toContain('reputation_evidence_contradictory')
        ->and($blockedResult->reasons)->toContain('reputation_health_blocked');
});

it('denies foreign-workspace sender and reputation evidence instead of reusing either', function () {
    $foreignSender = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        authentication: task0028SecurityCertAuthentication('workspace-b'),
    ));

    $foreignReputation = (new EvaluateReputationHealth)->evaluate(
        'workspace-a',
        'provider-a',
        [task0028SecurityCertReputationEvidence(
            ReputationHealthStatus::Healthy,
            'foreign-health',
            workspaceId: 'workspace-b',
        )],
        task0028SecurityCertAt(),
    );
    $foreignReputationResult = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        reputation: $foreignReputation,
    ));

    expect($foreignSender->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($foreignSender->reasons)->toContain('sender_authentication_workspace_mismatch')
        ->and($foreignReputationResult->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($foreignReputationResult->reasons)->toContain('reputation_evidence_workspace_mismatch');
});

it('does not let healthy sender frequency or reputation recreate missing marketing authorization', function () {
    $context = task0028SecurityCertContext(
        policyBasis: PolicyBasisType::None,
        basisEvidence: false,
    );
    $result = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        suppressionRequest: task0028SecurityCertSuppressionRequest(context: $context),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->senderIdentityReady)->toBeFalse()
        ->and($result->frequencyAllowed)->toBeFalse()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain('base_policy_outcome:review');
});

it('fails closed before routing decisions when provider policy context is missing', function () {
    $context = task0028SecurityCertContext(providerKey: null, providerKnown: false);
    $result = task0028SecurityCertEvaluator()->evaluate(task0028SecurityCertRequest(
        suppressionRequest: task0028SecurityCertSuppressionRequest(context: $context),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Unknown)
        ->and($result->providerKey)->toBeNull()
        ->and($result->senderIdentityReady)->toBeFalse()
        ->and($result->frequencyAllowed)->toBeFalse()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain('base_policy_outcome:unknown');
});
