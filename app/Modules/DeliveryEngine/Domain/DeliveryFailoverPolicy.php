<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryFailoverPolicy
{
    public function decide(
        DeliveryRouteAcceptanceState $previousRouteAcceptance,
        bool $sameWorkspace,
        bool $tenantChecksPass,
        bool $capabilityCompatible,
        bool $policyAllows,
        bool $connectionReady,
        bool $quotaAvailable,
        bool $breakerAllows,
    ): DeliveryFailoverDecision {
        if ($previousRouteAcceptance === DeliveryRouteAcceptanceState::Accepted) {
            return $this->deny('previous_route_accepted');
        }

        if ($previousRouteAcceptance === DeliveryRouteAcceptanceState::Ambiguous) {
            return $this->deny('previous_route_acceptance_unresolved');
        }

        if (! $sameWorkspace) {
            return $this->deny('alternate_route_crosses_workspace_boundary');
        }

        if (! $tenantChecksPass) {
            return $this->deny('alternate_route_tenant_checks_failed');
        }

        if (! $capabilityCompatible) {
            return $this->deny('alternate_route_capability_incompatible');
        }

        if (! $policyAllows) {
            return $this->deny('alternate_route_policy_denied');
        }

        if (! $connectionReady) {
            return $this->deny('alternate_route_not_ready');
        }

        if (! $quotaAvailable) {
            return $this->deny('alternate_route_quota_unavailable');
        }

        if (! $breakerAllows) {
            return $this->deny('alternate_route_breaker_blocked');
        }

        return new DeliveryFailoverDecision(
            eligible: true,
            createNewAttempt: true,
            preserveLogicalOperationIdentity: true,
            reason: 'alternate_route_eligible_after_proven_non_acceptance',
        );
    }

    private function deny(string $reason): DeliveryFailoverDecision
    {
        return new DeliveryFailoverDecision(
            eligible: false,
            createNewAttempt: false,
            preserveLogicalOperationIdentity: false,
            reason: $reason,
        );
    }
}
