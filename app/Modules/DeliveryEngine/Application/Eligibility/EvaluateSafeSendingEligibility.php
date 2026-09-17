<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationReadiness;

final readonly class EvaluateSafeSendingEligibility
{
    public function __construct(private EvaluateSuppressionAwareEligibility $suppressionAwareEligibility) {}

    public function evaluate(SafeSendingEligibilityRequest $request): SafeSendingEligibilityResult
    {
        $baseResult = $this->suppressionAwareEligibility->evaluate($request->suppressionAwareRequest);

        if ($baseResult->outcome !== EligibilityOutcome::Allow) {
            return $this->result(
                request: $request,
                baseResult: $baseResult,
                outcome: $baseResult->outcome,
                reasons: $baseResult->reasons,
            );
        }

        $authentication = $request->senderAuthentication;

        if ($authentication === null) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Unknown,
                ['sender_authentication_missing'],
            );
        }

        if ($authentication->workspaceId !== $request->workspaceId) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Deny,
                ['sender_authentication_workspace_mismatch'],
            );
        }

        if (trim($authentication->senderDomainId) === '') {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['sender_authentication_domain_missing'],
            );
        }

        if ($authentication->decidedAt > $request->evaluatedAt) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['sender_authentication_decision_from_future'],
            );
        }

        if ($authentication->readiness === AuthenticationReadiness::Failed) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Deny,
                ['sender_authentication_failed'],
            );
        }

        if ($authentication->readiness === AuthenticationReadiness::Unknown) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Unknown,
                ['sender_authentication_unknown'],
            );
        }

        if ($authentication->readiness === AuthenticationReadiness::Stale) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['sender_authentication_stale'],
            );
        }

        if ($authentication->readiness === AuthenticationReadiness::Contradictory) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['sender_authentication_contradictory'],
            );
        }

        $frequency = $request->frequencyEvaluation;

        if ($frequency === null) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['frequency_evaluation_missing'],
                senderIdentityReady: true,
            );
        }

        if ($frequency->evaluatedAt != $request->evaluatedAt) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['frequency_evaluation_time_mismatch'],
                senderIdentityReady: true,
            );
        }

        if ($frequency->outcome !== EligibilityOutcome::Allow) {
            return $this->result(
                $request,
                $baseResult,
                $frequency->outcome,
                array_merge(['frequency_outcome:'.$frequency->outcome->value], $frequency->reasons),
                senderIdentityReady: true,
            );
        }

        $reputation = $request->reputationEvaluation;

        if ($reputation === null) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Unknown,
                ['reputation_evaluation_missing'],
                senderIdentityReady: true,
                frequencyAllowed: true,
            );
        }

        $providerKey = $request->suppressionAwareRequest->policyContext->providerKey;

        if ($providerKey === null || $reputation->providerKey !== $providerKey) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['reputation_provider_context_mismatch'],
                senderIdentityReady: true,
                frequencyAllowed: true,
            );
        }

        if ($reputation->evaluatedAt != $request->evaluatedAt) {
            return $this->result(
                $request,
                $baseResult,
                EligibilityOutcome::Review,
                ['reputation_evaluation_time_mismatch'],
                senderIdentityReady: true,
                frequencyAllowed: true,
            );
        }

        if ($reputation->outcome !== EligibilityOutcome::Allow) {
            return $this->result(
                $request,
                $baseResult,
                $reputation->outcome,
                array_merge(['reputation_outcome:'.$reputation->outcome->value], $reputation->reasons),
                senderIdentityReady: true,
                frequencyAllowed: true,
            );
        }

        return $this->result(
            $request,
            $baseResult,
            EligibilityOutcome::Allow,
            ['safe_sending_allowed'],
            senderIdentityReady: true,
            frequencyAllowed: true,
            reputationHealthy: true,
        );
    }

    /** @param list<string> $reasons */
    private function result(
        SafeSendingEligibilityRequest $request,
        SuppressionAwareEligibilityResult $baseResult,
        EligibilityOutcome $outcome,
        array $reasons,
        bool $senderIdentityReady = false,
        bool $frequencyAllowed = false,
        bool $reputationHealthy = false,
    ): SafeSendingEligibilityResult {
        return new SafeSendingEligibilityResult(
            outcome: $outcome,
            messagePurpose: $request->suppressionAwareRequest->policyContext->messagePurpose,
            providerKey: $request->suppressionAwareRequest->policyContext->providerKey,
            suppressionAuthorityApplied: $baseResult->suppressionAuthorityApplied,
            senderIdentityReady: $senderIdentityReady,
            frequencyAllowed: $frequencyAllowed,
            reputationHealthy: $reputationHealthy,
            reasons: array_values(array_unique(array_merge($baseResult->reasons, $reasons))),
            evaluatedAt: $request->evaluatedAt,
        );
    }
}
