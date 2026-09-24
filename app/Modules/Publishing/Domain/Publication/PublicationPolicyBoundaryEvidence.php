<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;

final readonly class PublicationPolicyBoundaryEvidence
{
    public string $evidenceHash;

    public function __construct(
        public bool $workspaceAllowed,
        public bool $approvalValid,
        public bool $consentSuppressionAllowed,
        public bool $senderContentAllowed,
        public bool $assetAllowed,
        public bool $providerPolicyAllowed,
    ) {
        $this->evidenceHash = CampaignPayloadGuard::hash($this->canonicalPayload());
    }

    public function allPass(): bool
    {
        return $this->workspaceAllowed
            && $this->approvalValid
            && $this->consentSuppressionAllowed
            && $this->senderContentAllowed
            && $this->assetAllowed
            && $this->providerPolicyAllowed;
    }

    public function firstFailure(): ?string
    {
        foreach ($this->canonicalPayload() as $boundary => $allowed) {
            if ($allowed === false) {
                return $boundary;
            }
        }

        return null;
    }

    /** @return array<string, bool> */
    public function canonicalPayload(): array
    {
        return [
            'workspace_allowed' => $this->workspaceAllowed,
            'approval_valid' => $this->approvalValid,
            'consent_suppression_allowed' => $this->consentSuppressionAllowed,
            'sender_content_allowed' => $this->senderContentAllowed,
            'asset_allowed' => $this->assetAllowed,
            'provider_policy_allowed' => $this->providerPolicyAllowed,
        ];
    }
}
