<?php

use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;

function task0027EligibilityContext(
    MessageIntentType $purpose = MessageIntentType::Marketing,
    ?string $jurisdiction = 'GB',
    ?string $subscriberType = 'individual',
    ?string $solicitationBasis = 'direct_marketing',
    ?string $relationshipBasis = 'existing_customer',
    PolicyBasisType $basis = PolicyBasisType::ExplicitConsent,
    bool $basisEvidence = true,
    EligibilityOutcome $policyOutcome = EligibilityOutcome::Allow,
    ?string $policyVersion = 'gb-pecr-2026-02',
    ?DateTimeImmutable $policyEffectiveAt = null,
    ?string $providerKey = 'provider-a',
    bool $providerKnown = true,
    bool $suppression = false,
    bool $objection = false,
): EligibilityContext {
    return new EligibilityContext(
        messagePurpose: $purpose,
        jurisdiction: $jurisdiction,
        subscriberType: $subscriberType,
        solicitationBasis: $solicitationBasis,
        relationshipBasis: $relationshipBasis,
        policyBasis: $basis,
        policyBasisEvidencePresent: $basisEvidence,
        jurisdictionPolicyOutcome: $policyOutcome,
        policyVersion: $policyVersion,
        policyEffectiveAt: $policyVersion === null ? null : ($policyEffectiveAt ?? new DateTimeImmutable('2026-02-05T00:00:00+00:00')),
        providerKey: $providerKey,
        providerContextKnown: $providerKnown,
        suppressionApplies: $suppression,
        objectionApplies: $objection,
    );
}

it('lets suppression and objection deterministically override otherwise allowed policy', function () {
    $evaluator = new EvaluateDeliveryEligibility;

    expect($evaluator->evaluate(task0027EligibilityContext(suppression: true)))
        ->toBe(EligibilityOutcome::Deny)
        ->and($evaluator->evaluate(task0027EligibilityContext(objection: true)))
        ->toBe(EligibilityOutcome::Deny);
});

it('fails closed when jurisdiction provider or policy context is missing', function () {
    $evaluator = new EvaluateDeliveryEligibility;

    expect($evaluator->evaluate(task0027EligibilityContext(jurisdiction: null)))
        ->toBe(EligibilityOutcome::Unknown)
        ->and($evaluator->evaluate(task0027EligibilityContext(providerKnown: false)))
        ->toBe(EligibilityOutcome::Unknown)
        ->and($evaluator->evaluate(task0027EligibilityContext(policyVersion: null)))
        ->toBe(EligibilityOutcome::Unknown);
});

it('preserves external deny review and unknown jurisdiction policy outcomes', function () {
    $evaluator = new EvaluateDeliveryEligibility;

    expect($evaluator->evaluate(task0027EligibilityContext(policyOutcome: EligibilityOutcome::Deny)))
        ->toBe(EligibilityOutcome::Deny)
        ->and($evaluator->evaluate(task0027EligibilityContext(policyOutcome: EligibilityOutcome::Review)))
        ->toBe(EligibilityOutcome::Review)
        ->and($evaluator->evaluate(task0027EligibilityContext(policyOutcome: EligibilityOutcome::Unknown)))
        ->toBe(EligibilityOutcome::Unknown);
});

it('requires positive basis evidence before marketing can be allowed', function () {
    $evaluator = new EvaluateDeliveryEligibility;

    expect($evaluator->evaluate(task0027EligibilityContext(basis: PolicyBasisType::None)))
        ->toBe(EligibilityOutcome::Review)
        ->and($evaluator->evaluate(task0027EligibilityContext(basisEvidence: false)))
        ->toBe(EligibilityOutcome::Review)
        ->and($evaluator->evaluate(task0027EligibilityContext()))
        ->toBe(EligibilityOutcome::Allow);
});

it('keeps commercial and charitable purpose soft opt in as distinct policy bases', function () {
    expect(PolicyBasisType::CommercialSoftOptIn)
        ->not->toBe(PolicyBasisType::CharitablePurposeSoftOptIn)
        ->and(PolicyBasisType::CommercialSoftOptIn->value)->toBe('commercial_soft_opt_in')
        ->and(PolicyBasisType::CharitablePurposeSoftOptIn->value)->toBe('charitable_purpose_soft_opt_in');
});

it('does not require a marketing permission basis for transactional purpose once policy allows it', function () {
    $evaluator = new EvaluateDeliveryEligibility;

    expect($evaluator->evaluate(task0027EligibilityContext(
        purpose: MessageIntentType::Transactional,
        basis: PolicyBasisType::None,
        basisEvidence: false,
    )))->toBe(EligibilityOutcome::Allow);
});
