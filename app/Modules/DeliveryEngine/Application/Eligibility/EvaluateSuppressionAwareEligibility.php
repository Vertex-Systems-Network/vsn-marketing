<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;

final readonly class EvaluateSuppressionAwareEligibility
{
    public function __construct(private EvaluateDeliveryEligibility $baseEligibility) {}

    public function evaluate(SuppressionAwareEligibilityRequest $request): SuppressionAwareEligibilityResult
    {
        $reconciliation = $request->providerReconciliation;

        if ($request->canonicalSuppressionApplies || $request->canonicalObjectionApplies) {
            return new SuppressionAwareEligibilityResult(
                outcome: EligibilityOutcome::Deny,
                suppressionAuthorityApplied: true,
                providerReconciliationRestoredEligibility: false,
                reasons: [$request->canonicalObjectionApplies ? 'canonical_objection_applies' : 'canonical_suppression_applies'],
            );
        }

        if ($reconciliation !== null && ($reconciliation->internalSuppressionActive || $reconciliation->restoresEligibility)) {
            return new SuppressionAwareEligibilityResult(
                outcome: EligibilityOutcome::Deny,
                suppressionAuthorityApplied: true,
                providerReconciliationRestoredEligibility: false,
                reasons: [
                    $reconciliation->restoresEligibility
                        ? 'provider_reconciliation_cannot_restore_eligibility'
                        : 'provider_reconciliation_confirms_internal_suppression',
                ],
            );
        }

        $outcome = $this->baseEligibility->evaluate($request->policyContext);

        return new SuppressionAwareEligibilityResult(
            outcome: $outcome,
            suppressionAuthorityApplied: $request->policyContext->suppressionApplies || $request->policyContext->objectionApplies,
            providerReconciliationRestoredEligibility: false,
            reasons: ['base_policy_outcome:'.$outcome->value],
        );
    }
}
