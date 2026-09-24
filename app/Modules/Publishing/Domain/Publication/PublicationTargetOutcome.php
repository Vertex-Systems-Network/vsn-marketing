<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use InvalidArgumentException;

final readonly class PublicationTargetOutcome
{
    public function __construct(
        public string $targetId,
        public string $channel,
        public ?string $attemptId,
        public ?string $attemptHash,
        public ?PublicationAttemptState $attemptState,
        public ?ProviderOperationStatus $providerStatus,
        public ?string $projectionObservationHash,
        public ?int $projectionVersion,
        public PublicationTargetOutcomeState $state,
        public bool $retryEligible,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->targetId, 'publicationTargetOutcome.targetId');
        CampaignPayloadGuard::assertIdentifier($this->channel, 'publicationTargetOutcome.channel', 32);

        if ($this->attemptId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->attemptId, 'publicationTargetOutcome.attemptId');
        }

        if ($this->attemptHash !== null) {
            CampaignPayloadGuard::assertSha256($this->attemptHash, 'publicationTargetOutcome.attemptHash');
        }

        if ($this->projectionObservationHash !== null) {
            CampaignPayloadGuard::assertSha256(
                $this->projectionObservationHash,
                'publicationTargetOutcome.projectionObservationHash',
            );
        }

        if ($this->projectionVersion !== null && $this->projectionVersion < 1) {
            throw new InvalidArgumentException('Publication target projection version must be at least 1.');
        }

        if ($this->attemptId === null) {
            if (
                $this->attemptHash !== null
                || $this->attemptState !== null
                || $this->providerStatus !== null
                || $this->projectionObservationHash !== null
                || $this->projectionVersion !== null
                || $this->state !== PublicationTargetOutcomeState::NotStarted
                || $this->retryEligible
            ) {
                throw new InvalidArgumentException('A not-started publication target cannot contain attempt/provider evidence.');
            }
        }

        if ($this->retryEligible && (
            $this->state !== PublicationTargetOutcomeState::FailedRetriable
            || $this->attemptState !== PublicationAttemptState::FailedRetriable
            || $this->providerStatus !== ProviderOperationStatus::Failed
        )) {
            throw new InvalidArgumentException(
                'Publication retry eligibility requires failed_retriable attempt state plus trusted failed provider evidence.',
            );
        }

        if ($this->state === PublicationTargetOutcomeState::Succeeded && $this->retryEligible) {
            throw new InvalidArgumentException('Successful publication targets can never be retry eligible.');
        }
    }

    public static function fromEvidence(
        string $targetId,
        string $channel,
        ?PublicationAttempt $attempt,
        ?PublicationStatusProjection $projection,
    ): self {
        if ($attempt === null) {
            if ($projection !== null) {
                throw new InvalidArgumentException('Publication projection cannot exist without its canonical attempt.');
            }

            return new self(
                targetId: $targetId,
                channel: $channel,
                attemptId: null,
                attemptHash: null,
                attemptState: null,
                providerStatus: null,
                projectionObservationHash: null,
                projectionVersion: null,
                state: PublicationTargetOutcomeState::NotStarted,
                retryEligible: false,
            );
        }

        if ($attempt->targetId !== $targetId || $attempt->channel !== $channel) {
            throw new InvalidArgumentException('Publication attempt does not match immutable target authority.');
        }

        if ($projection !== null && (
            $projection->workspaceId !== $attempt->workspaceId
            || $projection->publicationAttemptId !== $attempt->id
        )) {
            throw new InvalidArgumentException('Publication status projection does not match its canonical attempt.');
        }

        $state = self::deriveState($attempt, $projection);
        $retryEligible = $state === PublicationTargetOutcomeState::FailedRetriable
            && $attempt->state === PublicationAttemptState::FailedRetriable
            && $projection?->normalizedStatus === ProviderOperationStatus::Failed;

        return new self(
            targetId: $targetId,
            channel: $channel,
            attemptId: $attempt->id,
            attemptHash: $attempt->attemptHash,
            attemptState: $attempt->state,
            providerStatus: $projection?->normalizedStatus,
            projectionObservationHash: $projection?->currentObservationHash,
            projectionVersion: $projection?->projectionVersion,
            state: $state,
            retryEligible: $retryEligible,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'target_id' => $this->targetId,
            'channel' => $this->channel,
            'attempt_id' => $this->attemptId,
            'attempt_hash' => $this->attemptHash,
            'attempt_state' => $this->attemptState?->value,
            'provider_status' => $this->providerStatus?->value,
            'projection_observation_hash' => $this->projectionObservationHash,
            'projection_version' => $this->projectionVersion,
            'state' => $this->state->value,
            'retry_eligible' => $this->retryEligible,
        ];
    }

    private static function deriveState(
        PublicationAttempt $attempt,
        ?PublicationStatusProjection $projection,
    ): PublicationTargetOutcomeState {
        if ($projection !== null) {
            return match ($projection->normalizedStatus) {
                ProviderOperationStatus::Succeeded => PublicationTargetOutcomeState::Succeeded,
                ProviderOperationStatus::Failed => match ($attempt->state) {
                    PublicationAttemptState::FailedRetriable => PublicationTargetOutcomeState::FailedRetriable,
                    PublicationAttemptState::FailedTerminal => PublicationTargetOutcomeState::FailedTerminal,
                    default => PublicationTargetOutcomeState::FailedUnclassified,
                },
                ProviderOperationStatus::Cancelled => PublicationTargetOutcomeState::Cancelled,
                ProviderOperationStatus::InProgress => PublicationTargetOutcomeState::InProgress,
                ProviderOperationStatus::Accepted,
                ProviderOperationStatus::Pending => PublicationTargetOutcomeState::Pending,
                ProviderOperationStatus::Unknown => self::fromAttemptState($attempt->state),
            };
        }

        return self::fromAttemptState($attempt->state);
    }

    private static function fromAttemptState(PublicationAttemptState $state): PublicationTargetOutcomeState
    {
        return match ($state) {
            PublicationAttemptState::Prepared => PublicationTargetOutcomeState::Pending,
            PublicationAttemptState::Dispatching => PublicationTargetOutcomeState::InProgress,
            PublicationAttemptState::Published => PublicationTargetOutcomeState::Succeeded,
            PublicationAttemptState::FailedRetriable => PublicationTargetOutcomeState::FailedRetriable,
            PublicationAttemptState::FailedTerminal => PublicationTargetOutcomeState::FailedTerminal,
            PublicationAttemptState::Cancelled => PublicationTargetOutcomeState::Cancelled,
        };
    }
}
