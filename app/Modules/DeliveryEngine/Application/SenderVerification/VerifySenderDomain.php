<?php

namespace App\Modules\DeliveryEngine\Application\SenderVerification;

use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidenceEvaluator;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicyEvaluator;
use App\Modules\Providers\Domain\SenderPolicy\ProviderVolumeClassification;

final readonly class VerifySenderDomain
{
    public function __construct(
        private AuthenticationEvidenceEvaluator $authenticationEvaluator,
        private MailboxProviderPolicyEvaluator $providerPolicyEvaluator,
    ) {}

    public function handle(SenderVerificationRequest $request): SenderVerificationResult
    {
        $authentication = $this->authenticationEvaluator->evaluate(
            workspaceId: $request->workspaceId,
            senderDomainId: $request->senderDomainId,
            evidence: $request->authenticationEvidence,
            at: $request->evaluatedAt,
        );

        $providerPolicy = $this->providerPolicyEvaluator->decide(
            workspaceId: $request->workspaceId,
            providerKey: $request->providerKey,
            policies: $request->providerPolicies,
            at: $request->evaluatedAt,
            observedVolume: $request->observedVolume,
            configuredHighVolume: $request->configuredHighVolume,
        );

        $reasons = [];

        if ($request->observationOutcome === SenderVerificationObservationOutcome::Timeout) {
            $reasons[] = 'verification_observation_timeout';
        } elseif ($request->observationOutcome === SenderVerificationObservationOutcome::Ambiguous) {
            $reasons[] = 'verification_observation_ambiguous';
        }

        foreach ($authentication->reasons as $reason) {
            $reasons[] = 'authentication:'.$reason;
        }

        foreach ($providerPolicy->reasons as $reason) {
            $reasons[] = 'provider_policy:'.$reason;
        }

        $policyContextKnown = $providerPolicy->policyKey !== null
            && $providerPolicy->policyVersion !== null
            && ! in_array(
                $providerPolicy->classification,
                [ProviderVolumeClassification::Unknown, ProviderVolumeClassification::ConfigurationRequired],
                true,
            );

        $eligibleForLaterSendingEvaluation = $request->observationOutcome === SenderVerificationObservationOutcome::Completed
            && $authentication->isReady()
            && $policyContextKnown;

        if (! $authentication->isReady()) {
            $reasons[] = 'authentication_not_ready';
        }

        if (! $policyContextKnown) {
            $reasons[] = 'provider_policy_context_not_ready';
        }

        if ($eligibleForLaterSendingEvaluation) {
            $reasons[] = 'verification_ready_for_later_sending_evaluation';
        }

        $reasons[] = 'production_activation_requires_separate_gate';

        return new SenderVerificationResult(
            operationKey: $request->operationKey,
            workspaceId: $request->workspaceId,
            senderDomainId: $request->senderDomainId,
            providerKey: $request->providerKey,
            observationOutcome: $request->observationOutcome,
            authentication: $authentication,
            providerPolicy: $providerPolicy,
            eligibleForLaterSendingEvaluation: $eligibleForLaterSendingEvaluation,
            productionActivationAllowed: false,
            reasons: array_values(array_unique($reasons)),
            evaluatedAt: $request->evaluatedAt,
        );
    }
}
