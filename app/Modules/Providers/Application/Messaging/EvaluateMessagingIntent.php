<?php

namespace App\Modules\Providers\Application\Messaging;

use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\Providers\Domain\Messaging\MessagingCapability;
use App\Modules\Providers\Domain\Messaging\MessagingDecision;
use App\Modules\Providers\Domain\Messaging\MessagingIntent;
use DateTimeImmutable;

final readonly class EvaluateMessagingIntent
{
    public function __construct(private EvaluateSuppressionAwareEligibility $eligibility) {}

    /** @param list<string> $grantedScopes */
    public function evaluate(MessagingIntent $intent, MessagingCapability $capability, array $grantedScopes, DateTimeImmutable $at): MessagingDecision
    {
        $reasons = [];
        if ($intent->workspaceId !== $intent->accountWorkspaceId) {
            $reasons[] = 'account_workspace_mismatch';
        }
        if ($intent->providerKey !== $capability->providerKey || $intent->channel !== $capability->channel) {
            $reasons[] = 'provider_capability_mismatch';
        }
        if ($intent->recipientIdentityType !== $intent->channel->recipientIdentity()) {
            $reasons[] = 'recipient_identity_mismatch';
        }
        if (! $capability->supportsOfflinePreparation($grantedScopes, $at)) {
            $reasons[] = 'capability_unavailable_or_stale';
        }
        if (! $intent->channelPurposeAuthorized) {
            $reasons[] = 'channel_purpose_unapproved';
        }
        if ($intent->providerOptOutApplies) {
            $reasons[] = 'provider_opt_out';
        }
        if (! $intent->accountApproved) {
            $reasons[] = 'account_unapproved';
        }
        if (! $intent->rateBudgetAvailable) {
            $reasons[] = 'rate_budget_exhausted';
        }
        if ($intent->eligibility->policyContext->providerKey !== $intent->providerKey) {
            $reasons[] = 'policy_provider_mismatch';
        }
        if ($this->eligibility->evaluate($intent->eligibility)->outcome !== EligibilityOutcome::Allow) {
            $reasons[] = 'canonical_eligibility_not_allow';
        }

        return new MessagingDecision(
            offlinePreparationAllowed: $reasons === [],
            liveDeliveryAllowed: false,
            reasons: $reasons === [] ? ['offline_only_provider_unbound'] : $reasons,
        );
    }
}
