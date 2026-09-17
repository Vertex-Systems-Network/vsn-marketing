<?php

use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSafeSendingEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\SafeSendingEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Frequency\FrequencyEvaluationResult;
use App\Modules\DeliveryEngine\Application\Reputation\ReputationHealthEvaluationResult;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDecision;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationReadiness;

function task0028SafeAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-17T12:15:00+00:00');
}

function task0028SafeEligibilityContext(
    MessageIntentType $purpose = MessageIntentType::Marketing,
    PolicyBasisType $policyBasis = PolicyBasisType::ExplicitConsent,
    bool $policyBasisEvidencePresent = true,
    EligibilityOutcome $jurisdictionPolicyOutcome = EligibilityOutcome::Allow,
    ?string $providerKey = 'provider-a',
): EligibilityContext {
    return new EligibilityContext(
        messagePurpose: $purpose,
        jurisdiction: 'GB',
        subscriberType: 'individual',
        solicitationBasis: $purpose === MessageIntentType::Marketing ? 'direct_marketing' : 'service_delivery',
        relationshipBasis: 'existing_customer',
        policyBasis: $policyBasis,
        policyBasisEvidencePresent: $policyBasisEvidencePresent,
        jurisdictionPolicyOutcome: $jurisdictionPolicyOutcome,
        policyVersion: 'gb-pecr-2026-02',
        policyEffectiveAt: new DateTimeImmutable('2026-02-05T00:00:00+00:00'),
        providerKey: $providerKey,
        providerContextKnown: $providerKey !== null,
        suppressionApplies: false,
        objectionApplies: false,
    );
}

function task0028SafeSuppressionRequest(
    ?EligibilityContext $context = null,
    bool $canonicalSuppression = false,
    bool $canonicalObjection = false,
): SuppressionAwareEligibilityRequest {
    return new SuppressionAwareEligibilityRequest(
        policyContext: $context ?? task0028SafeEligibilityContext(),
        canonicalSuppressionApplies: $canonicalSuppression,
        canonicalObjectionApplies: $canonicalObjection,
    );
}

function task0028SafeAuthentication(
    AuthenticationReadiness $readiness = AuthenticationReadiness::Ready,
    string $workspaceId = 'workspace-a',
    string $senderDomainId = 'sender-domain-a',
    ?DateTimeImmutable $decidedAt = null,
): AuthenticationDecision {
    return new AuthenticationDecision(
        workspaceId: $workspaceId,
        senderDomainId: $senderDomainId,
        readiness: $readiness,
        reasons: ['authentication_evaluated'],
        evidenceVersions: ['spf-v1', 'dkim-v1', 'dmarc-v1'],
        decidedAt: $decidedAt ?? task0028SafeAt(),
    );
}

function task0028SafeFrequency(
    EligibilityOutcome $outcome = EligibilityOutcome::Allow,
    array $reasons = ['frequency_within_limit'],
    ?DateTimeImmutable $evaluatedAt = null,
): FrequencyEvaluationResult {
    $at = $evaluatedAt ?? task0028SafeAt();

    return new FrequencyEvaluationResult(
        outcome: $outcome,
        reasons: $reasons,
        evaluatedAt: $at,
        replay: false,
        currentCount: 1,
        nextCount: $outcome === EligibilityOutcome::Allow ? 2 : 1,
        windowStart: new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
        windowEnd: new DateTimeImmutable('2026-09-17T13:00:00+00:00'),
    );
}

function task0028SafeReputation(
    EligibilityOutcome $outcome = EligibilityOutcome::Allow,
    array $reasons = ['reputation_health_healthy'],
    string $providerKey = 'provider-a',
    ?DateTimeImmutable $evaluatedAt = null,
): ReputationHealthEvaluationResult {
    return new ReputationHealthEvaluationResult(
        outcome: $outcome,
        reasons: $reasons,
        evaluatedAt: $evaluatedAt ?? task0028SafeAt(),
        providerKey: $providerKey,
        evidenceIds: ['evidence-a'],
    );
}

function task0028SafeRequest(
    ?SuppressionAwareEligibilityRequest $suppressionRequest = null,
    ?AuthenticationDecision $authentication = null,
    ?FrequencyEvaluationResult $frequency = null,
    ?ReputationHealthEvaluationResult $reputation = null,
    string $workspaceId = 'workspace-a',
    ?DateTimeImmutable $evaluatedAt = null,
): SafeSendingEligibilityRequest {
    return new SafeSendingEligibilityRequest(
        workspaceId: $workspaceId,
        suppressionAwareRequest: $suppressionRequest ?? task0028SafeSuppressionRequest(),
        senderAuthentication: $authentication ?? task0028SafeAuthentication(),
        frequencyEvaluation: $frequency ?? task0028SafeFrequency(),
        reputationEvaluation: $reputation ?? task0028SafeReputation(),
        evaluatedAt: $evaluatedAt ?? task0028SafeAt(),
    );
}

function task0028SafeEvaluator(): EvaluateSafeSendingEligibility
{
    return new EvaluateSafeSendingEligibility(
        new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility),
    );
}

it('allows only when base authority sender frequency and reputation all allow', function () {
    $result = task0028SafeEvaluator()->evaluate(task0028SafeRequest());

    expect($result->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($result->messagePurpose)->toBe(MessageIntentType::Marketing)
        ->and($result->providerKey)->toBe('provider-a')
        ->and($result->senderIdentityReady)->toBeTrue()
        ->and($result->frequencyAllowed)->toBeTrue()
        ->and($result->reputationHealthy)->toBeTrue()
        ->and($result->reasons)->toContain('safe_sending_allowed');
});

it('keeps canonical suppression and objection as absolute higher order authority', function () {
    $suppressed = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        suppressionRequest: task0028SafeSuppressionRequest(canonicalSuppression: true),
    ));

    $objected = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        suppressionRequest: task0028SafeSuppressionRequest(canonicalObjection: true),
    ));

    expect($suppressed->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($suppressed->suppressionAuthorityApplied)->toBeTrue()
        ->and($suppressed->reasons)->toContain('canonical_suppression_applies')
        ->and($objected->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($objected->suppressionAuthorityApplied)->toBeTrue()
        ->and($objected->reasons)->toContain('canonical_objection_applies');
});

it('does not let downstream signals recreate missing marketing authorization', function () {
    $context = task0028SafeEligibilityContext(
        policyBasis: PolicyBasisType::None,
        policyBasisEvidencePresent: false,
    );

    $result = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        suppressionRequest: task0028SafeSuppressionRequest(context: $context),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->senderIdentityReady)->toBeFalse()
        ->and($result->frequencyAllowed)->toBeFalse()
        ->and($result->reputationHealthy)->toBeFalse()
        ->and($result->reasons)->toContain('base_policy_outcome:review');
});

it('fails closed when sender authentication is missing foreign failed stale or contradictory', function () {
    $missing = task0028SafeEvaluator()->evaluate(new SafeSendingEligibilityRequest(
        workspaceId: 'workspace-a',
        suppressionAwareRequest: task0028SafeSuppressionRequest(),
        senderAuthentication: null,
        frequencyEvaluation: task0028SafeFrequency(),
        reputationEvaluation: task0028SafeReputation(),
        evaluatedAt: task0028SafeAt(),
    ));

    $foreign = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        authentication: task0028SafeAuthentication(workspaceId: 'workspace-b'),
    ));

    $failed = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        authentication: task0028SafeAuthentication(AuthenticationReadiness::Failed),
    ));

    $stale = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        authentication: task0028SafeAuthentication(AuthenticationReadiness::Stale),
    ));

    $contradictory = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        authentication: task0028SafeAuthentication(AuthenticationReadiness::Contradictory),
    ));

    expect($missing->outcome)->toBe(EligibilityOutcome::Unknown)
        ->and($missing->reasons)->toContain('sender_authentication_missing')
        ->and($foreign->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($foreign->reasons)->toContain('sender_authentication_workspace_mismatch')
        ->and($failed->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($failed->reasons)->toContain('sender_authentication_failed')
        ->and($stale->outcome)->toBe(EligibilityOutcome::Review)
        ->and($stale->reasons)->toContain('sender_authentication_stale')
        ->and($contradictory->outcome)->toBe(EligibilityOutcome::Review)
        ->and($contradictory->reasons)->toContain('sender_authentication_contradictory');
});

it('does not let healthy reputation bypass a frequency denial or review', function () {
    $denied = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        frequency: task0028SafeFrequency(EligibilityOutcome::Deny, ['frequency_limit_reached']),
    ));

    $review = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        frequency: task0028SafeFrequency(EligibilityOutcome::Review, ['frequency_counter_missing']),
    ));

    expect($denied->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($denied->frequencyAllowed)->toBeFalse()
        ->and($denied->reputationHealthy)->toBeFalse()
        ->and($denied->reasons)->toContain('frequency_limit_reached')
        ->and($review->outcome)->toBe(EligibilityOutcome::Review)
        ->and($review->reasons)->toContain('frequency_counter_missing');
});

it('fails closed when frequency or reputation evaluation is missing', function () {
    $missingFrequency = task0028SafeEvaluator()->evaluate(new SafeSendingEligibilityRequest(
        workspaceId: 'workspace-a',
        suppressionAwareRequest: task0028SafeSuppressionRequest(),
        senderAuthentication: task0028SafeAuthentication(),
        frequencyEvaluation: null,
        reputationEvaluation: task0028SafeReputation(),
        evaluatedAt: task0028SafeAt(),
    ));

    $missingReputation = task0028SafeEvaluator()->evaluate(new SafeSendingEligibilityRequest(
        workspaceId: 'workspace-a',
        suppressionAwareRequest: task0028SafeSuppressionRequest(),
        senderAuthentication: task0028SafeAuthentication(),
        frequencyEvaluation: task0028SafeFrequency(),
        reputationEvaluation: null,
        evaluatedAt: task0028SafeAt(),
    ));

    expect($missingFrequency->outcome)->toBe(EligibilityOutcome::Review)
        ->and($missingFrequency->reasons)->toContain('frequency_evaluation_missing')
        ->and($missingReputation->outcome)->toBe(EligibilityOutcome::Unknown)
        ->and($missingReputation->reasons)->toContain('reputation_evaluation_missing');
});

it('does not let provider health override blocked or uncertain reputation outcomes', function () {
    $blocked = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        reputation: task0028SafeReputation(EligibilityOutcome::Deny, ['reputation_health_blocked']),
    ));

    $uncertain = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        reputation: task0028SafeReputation(EligibilityOutcome::Unknown, ['reputation_evidence_missing']),
    ));

    expect($blocked->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($blocked->reasons)->toContain('reputation_health_blocked')
        ->and($uncertain->outcome)->toBe(EligibilityOutcome::Unknown)
        ->and($uncertain->reasons)->toContain('reputation_evidence_missing');
});

it('reviews mismatched provider context or mismatched evaluation timestamps', function () {
    $providerMismatch = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        reputation: task0028SafeReputation(providerKey: 'provider-b'),
    ));

    $frequencyTimeMismatch = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        frequency: task0028SafeFrequency(evaluatedAt: task0028SafeAt()->modify('-1 minute')),
    ));

    $reputationTimeMismatch = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        reputation: task0028SafeReputation(evaluatedAt: task0028SafeAt()->modify('-1 minute')),
    ));

    expect($providerMismatch->outcome)->toBe(EligibilityOutcome::Review)
        ->and($providerMismatch->reasons)->toContain('reputation_provider_context_mismatch')
        ->and($frequencyTimeMismatch->outcome)->toBe(EligibilityOutcome::Review)
        ->and($frequencyTimeMismatch->reasons)->toContain('frequency_evaluation_time_mismatch')
        ->and($reputationTimeMismatch->outcome)->toBe(EligibilityOutcome::Review)
        ->and($reputationTimeMismatch->reasons)->toContain('reputation_evaluation_time_mismatch');
});

it('preserves explicit transactional purpose rather than inferring purpose from provider health', function () {
    $context = task0028SafeEligibilityContext(
        purpose: MessageIntentType::Transactional,
        policyBasis: PolicyBasisType::None,
        policyBasisEvidencePresent: false,
    );

    $result = task0028SafeEvaluator()->evaluate(task0028SafeRequest(
        suppressionRequest: task0028SafeSuppressionRequest(context: $context),
    ));

    expect($result->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($result->messagePurpose)->toBe(MessageIntentType::Transactional)
        ->and($result->reasons)->toContain('safe_sending_allowed');
});
