<?php

use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationOutcome;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationResult;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;

function task0027AllowingEligibilityContext(): EligibilityContext
{
    return new EligibilityContext(
        messagePurpose: MessageIntentType::Marketing,
        jurisdiction: 'GB',
        subscriberType: 'individual',
        solicitationBasis: 'direct_marketing',
        relationshipBasis: 'customer',
        policyBasis: PolicyBasisType::ExplicitConsent,
        policyBasisEvidencePresent: true,
        jurisdictionPolicyOutcome: EligibilityOutcome::Allow,
        policyVersion: 'uk-2026-1',
        policyEffectiveAt: new DateTimeImmutable('2026-06-19T00:00:00+00:00'),
        providerKey: 'provider-a',
        providerContextKnown: true,
        suppressionApplies: false,
        objectionApplies: false,
    );
}

function task0027SuppressionSync(
    SuppressionSynchronizationOutcome $outcome,
    bool $internalSuppressionActive,
    bool $restoresEligibility,
): SuppressionSynchronizationResult {
    return new SuppressionSynchronizationResult(
        operationKey: 'sync:task0027',
        workspaceId: 'workspace-a',
        suppressionRecordId: 'suppression-a',
        providerKey: 'provider-a',
        providerOutcome: $outcome,
        reconciliationRequired: $outcome->requiresReconciliation(),
        internalSuppressionActive: $internalSuppressionActive,
        restoresEligibility: $restoresEligibility,
        reasons: ['test'],
        observedAt: new DateTimeImmutable('2026-09-17T12:00:00+00:00'),
        providerReference: null,
    );
}

it('denies before routing when canonical suppression applies even if base policy allows', function () {
    $result = (new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility))->evaluate(
        new SuppressionAwareEligibilityRequest(
            policyContext: task0027AllowingEligibilityContext(),
            canonicalSuppressionApplies: true,
            canonicalObjectionApplies: false,
        ),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->suppressionAuthorityApplied)->toBeTrue()
        ->and($result->providerReconciliationRestoredEligibility)->toBeFalse();
});

it('denies before routing when direct marketing objection applies', function () {
    $result = (new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility))->evaluate(
        new SuppressionAwareEligibilityRequest(
            policyContext: task0027AllowingEligibilityContext(),
            canonicalSuppressionApplies: false,
            canonicalObjectionApplies: true,
        ),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->reasons)->toContain('canonical_objection_applies');
});

it('keeps internal suppression authoritative while provider reconciliation is ambiguous', function () {
    $result = (new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility))->evaluate(
        new SuppressionAwareEligibilityRequest(
            policyContext: task0027AllowingEligibilityContext(),
            canonicalSuppressionApplies: false,
            canonicalObjectionApplies: false,
            providerReconciliation: task0027SuppressionSync(
                SuppressionSynchronizationOutcome::Ambiguous,
                internalSuppressionActive: true,
                restoresEligibility: false,
            ),
        ),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->providerReconciliationRestoredEligibility)->toBeFalse();
});

it('fails closed if any provider reconciliation claims it can restore eligibility', function () {
    $result = (new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility))->evaluate(
        new SuppressionAwareEligibilityRequest(
            policyContext: task0027AllowingEligibilityContext(),
            canonicalSuppressionApplies: false,
            canonicalObjectionApplies: false,
            providerReconciliation: task0027SuppressionSync(
                SuppressionSynchronizationOutcome::Confirmed,
                internalSuppressionActive: false,
                restoresEligibility: true,
            ),
        ),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->reasons)->toContain('provider_reconciliation_cannot_restore_eligibility');
});

it('preserves unknown policy outcomes when no suppression authority is present', function () {
    $context = new EligibilityContext(
        messagePurpose: MessageIntentType::Marketing,
        jurisdiction: null,
        subscriberType: null,
        solicitationBasis: null,
        relationshipBasis: null,
        policyBasis: PolicyBasisType::None,
        policyBasisEvidencePresent: false,
        jurisdictionPolicyOutcome: EligibilityOutcome::Unknown,
        policyVersion: null,
        policyEffectiveAt: null,
        providerKey: null,
        providerContextKnown: false,
        suppressionApplies: false,
        objectionApplies: false,
    );

    $result = (new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility))->evaluate(
        new SuppressionAwareEligibilityRequest($context, false, false),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Unknown);
});

it('allows only when no suppression authority applies and the base policy explicitly allows', function () {
    $result = (new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility))->evaluate(
        new SuppressionAwareEligibilityRequest(task0027AllowingEligibilityContext(), false, false),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($result->suppressionAuthorityApplied)->toBeFalse();
});
