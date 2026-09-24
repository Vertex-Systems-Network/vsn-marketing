<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicationStatusProjection
{
    public function __construct(
        public string $workspaceId,
        public string $publicationAttemptId,
        public string $providerConnectionId,
        public string $capabilityEvidenceId,
        public string $providerId,
        public string $providerOperationId,
        public ProviderOperationStatus $normalizedStatus,
        public string $providerStatus,
        public DateTimeImmutable $providerObservedAt,
        public ReconciliationSource $source,
        public string $sourceReference,
        public string $currentObservationId,
        public string $currentObservationHash,
        public int $projectionVersion,
        public DateTimeImmutable $updatedAt,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'publicationAttemptId' => $this->publicationAttemptId,
            'providerConnectionId' => $this->providerConnectionId,
            'capabilityEvidenceId' => $this->capabilityEvidenceId,
            'providerId' => $this->providerId,
            'currentObservationId' => $this->currentObservationId,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'publicationStatusProjection.'.$field);
        }

        if (trim($this->providerOperationId) === '' || mb_strlen($this->providerOperationId) > 512) {
            throw new InvalidArgumentException('Publication status projection provider operation ID is invalid.');
        }

        if (trim($this->providerStatus) === '' || mb_strlen($this->providerStatus) > 120) {
            throw new InvalidArgumentException('Publication status projection provider status is invalid.');
        }

        if (trim($this->sourceReference) === '' || mb_strlen($this->sourceReference) > 191) {
            throw new InvalidArgumentException('Publication status projection source reference is invalid.');
        }

        CampaignPayloadGuard::assertSha256($this->currentObservationHash, 'publicationStatusProjection.currentObservationHash');

        if ($this->projectionVersion < 1) {
            throw new InvalidArgumentException('Publication status projection version must be at least 1.');
        }

        if ($this->providerObservedAt->getOffset() !== 0 || $this->updatedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Publication status projection timestamps must be normalized to UTC.');
        }

        if ($this->providerObservedAt > $this->updatedAt) {
            throw new InvalidArgumentException('Publication status projection provider timestamp cannot exceed update time.');
        }
    }

    public static function initial(PublicationStatusObservation $observation): self
    {
        return new self(
            workspaceId: $observation->workspaceId,
            publicationAttemptId: $observation->publicationAttemptId,
            providerConnectionId: $observation->providerConnectionId,
            capabilityEvidenceId: $observation->capabilityEvidenceId,
            providerId: $observation->providerId,
            providerOperationId: $observation->providerOperationId,
            normalizedStatus: $observation->normalizedStatus,
            providerStatus: $observation->providerStatus,
            providerObservedAt: $observation->providerObservedAt,
            source: $observation->source,
            sourceReference: $observation->sourceReference,
            currentObservationId: $observation->id,
            currentObservationHash: $observation->observationHash,
            projectionVersion: 1,
            updatedAt: $observation->receivedAt,
        );
    }

    public function apply(PublicationStatusObservation $observation): self
    {
        if (
            $observation->workspaceId !== $this->workspaceId
            || $observation->publicationAttemptId !== $this->publicationAttemptId
            || $observation->providerConnectionId !== $this->providerConnectionId
            || $observation->capabilityEvidenceId !== $this->capabilityEvidenceId
            || $observation->providerId !== $this->providerId
        ) {
            throw new InvalidArgumentException('Publication status observation does not match projection authority.');
        }

        if ($observation->providerOperationId !== $this->providerOperationId) {
            throw new InvalidArgumentException('Publication status observation does not match canonical provider operation ID.');
        }

        if ($observation->providerObservedAt < $this->providerObservedAt) {
            return $this;
        }

        if ($this->normalizedStatus->isTerminal()) {
            if (
                $observation->normalizedStatus !== $this->normalizedStatus
                || $observation->providerObservedAt <= $this->providerObservedAt
            ) {
                return $this;
            }

            return $this->advancedTo($observation);
        }

        if ($observation->normalizedStatus === ProviderOperationStatus::Unknown) {
            return $this;
        }

        if ($observation->providerObservedAt == $this->providerObservedAt) {
            if ($observation->normalizedStatus === $this->normalizedStatus) {
                return $this;
            }

            if (! $this->normalizedStatus->canAdvanceTo($observation->normalizedStatus)) {
                return $this;
            }

            return $this->advancedTo($observation);
        }

        if (
            $observation->normalizedStatus !== $this->normalizedStatus
            && ! $this->normalizedStatus->canAdvanceTo($observation->normalizedStatus)
        ) {
            return $this;
        }

        return $this->advancedTo($observation);
    }

    private function advancedTo(PublicationStatusObservation $observation): self
    {
        return new self(
            workspaceId: $this->workspaceId,
            publicationAttemptId: $this->publicationAttemptId,
            providerConnectionId: $this->providerConnectionId,
            capabilityEvidenceId: $this->capabilityEvidenceId,
            providerId: $this->providerId,
            providerOperationId: $this->providerOperationId,
            normalizedStatus: $observation->normalizedStatus,
            providerStatus: $observation->providerStatus,
            providerObservedAt: $observation->providerObservedAt,
            source: $observation->source,
            sourceReference: $observation->sourceReference,
            currentObservationId: $observation->id,
            currentObservationHash: $observation->observationHash,
            projectionVersion: $this->projectionVersion + 1,
            updatedAt: $observation->receivedAt < $this->updatedAt ? $this->updatedAt : $observation->receivedAt,
        );
    }
}
