<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicationProviderOutcome
{
    public string $outcomeHash;

    public function __construct(
        public string $workspaceId,
        public string $publicationAttemptId,
        public string $authorizationHash,
        public PublicationProviderOutcomeCode $code,
        public PublicationProviderCircuitState $circuitState,
        public string $policyBoundaryEvidenceHash,
        public ?string $failedPolicyBoundary,
        public ?ProviderErrorCategory $providerErrorCategory,
        public ?int $retryAfterSeconds,
        public ?string $currentCapabilityEvidenceId,
        public ?string $currentCapabilitySourceVersion,
        public bool $retryEligible,
        public bool $workHeld,
        public bool $fallbackAllowed,
        public DateTimeImmutable $evaluatedAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'publicationProviderOutcome.workspaceId');
        CampaignPayloadGuard::assertIdentifier($publicationAttemptId, 'publicationProviderOutcome.publicationAttemptId');
        CampaignPayloadGuard::assertSha256($authorizationHash, 'publicationProviderOutcome.authorizationHash');
        CampaignPayloadGuard::assertSha256(
            $policyBoundaryEvidenceHash,
            'publicationProviderOutcome.policyBoundaryEvidenceHash',
        );

        if ($currentCapabilityEvidenceId !== null) {
            CampaignPayloadGuard::assertIdentifier(
                $currentCapabilityEvidenceId,
                'publicationProviderOutcome.currentCapabilityEvidenceId',
            );
        }

        if ($currentCapabilitySourceVersion !== null) {
            CampaignPayloadGuard::assertIdentifier(
                $currentCapabilitySourceVersion,
                'publicationProviderOutcome.currentCapabilitySourceVersion',
            );
        }

        if ($retryAfterSeconds !== null && ($retryAfterSeconds < 0 || $retryAfterSeconds > 86400)) {
            throw new InvalidArgumentException('Publication provider retry delay must be between 0 and 86400 seconds.');
        }

        if ($evaluatedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Publication provider outcome timestamp must be normalized to UTC.');
        }

        if ($fallbackAllowed) {
            throw new InvalidArgumentException('Publication provider outcomes may never authorize policy-bypassing fallback.');
        }

        if ($code === PublicationProviderOutcomeCode::PolicyBoundaryDenied && $failedPolicyBoundary === null) {
            throw new InvalidArgumentException('Policy-boundary denial requires the failed boundary name.');
        }

        if ($code !== PublicationProviderOutcomeCode::PolicyBoundaryDenied && $failedPolicyBoundary !== null) {
            throw new InvalidArgumentException('Only policy-boundary denial may carry a failed boundary name.');
        }

        if ($retryEligible && ! in_array($code, [
            PublicationProviderOutcomeCode::RateLimited,
            PublicationProviderOutcomeCode::ProviderRetryable,
        ], true)) {
            throw new InvalidArgumentException('Only explicit retryable provider outcomes may be retry eligible.');
        }

        $this->outcomeHash = CampaignPayloadGuard::hash($this->canonicalPayload());
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'operation' => 'publication.provider.outcome',
            'workspace_id' => $this->workspaceId,
            'publication_attempt_id' => $this->publicationAttemptId,
            'authorization_hash' => $this->authorizationHash,
            'outcome_code' => $this->code->value,
            'circuit_state' => $this->circuitState->value,
            'policy_boundary_evidence_hash' => $this->policyBoundaryEvidenceHash,
            'failed_policy_boundary' => $this->failedPolicyBoundary,
            'provider_error_category' => $this->providerErrorCategory?->value,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'current_capability_evidence_id' => $this->currentCapabilityEvidenceId,
            'current_capability_source_version' => $this->currentCapabilitySourceVersion,
            'retry_eligible' => $this->retryEligible,
            'work_held' => $this->workHeld,
            'fallback_allowed' => $this->fallbackAllowed,
            'evaluated_at' => $this->evaluatedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
