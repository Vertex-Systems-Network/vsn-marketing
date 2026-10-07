<?php

namespace App\Modules\Providers\Infrastructure\Social;

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Social\SocialCapability;
use App\Modules\Providers\Domain\Social\SocialPreparationDecision;
use App\Modules\Providers\Domain\Social\SocialPublicationIntent;
use DateTimeImmutable;

final readonly class OfflineSocialPublishingAdapter
{
    public function __construct(private SocialCapability $capability) {}

    /**
     * @param list<string> $grantedScopes
     * @param list<string> $roles
     */
    public function prepare(
        SocialPublicationIntent $intent,
        array $grantedScopes,
        array $roles,
        DateTimeImmutable $at,
    ): SocialPreparationDecision {
        $reasons = [];

        if ($intent->workspaceId !== $intent->accountWorkspaceId) {
            $reasons[] = 'account_workspace_mismatch';
        }
        if ($intent->platform !== $this->capability->platform
            || $intent->operation !== $this->capability->operation
            || $intent->providerKey !== $this->capability->providerKey) {
            $reasons[] = 'capability_identity_mismatch';
        }
        if (! $intent->approvalValid) {
            $reasons[] = 'canonical_approval_invalid';
        }
        if (! $intent->rateBudgetAvailable) {
            $reasons[] = 'rate_budget_exhausted';
        }
        if (! $this->capability->isUsableOffline($grantedScopes, $roles, $at)) {
            $reasons[] = 'capability_unavailable_or_stale';
        }

        $reasons = array_values(array_unique($reasons));

        return new SocialPreparationDecision($reasons === [], false, $reasons);
    }

    public function normalizeProviderOutcome(
        ProviderOperationStatus $status,
        bool $ambiguous,
    ): ProviderOperationStatus {
        return $ambiguous ? ProviderOperationStatus::Unknown : $status;
    }
}
