<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicationStatusObservation
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $publicationAttemptId,
        public string $providerConnectionId,
        public string $capabilityEvidenceId,
        public string $providerId,
        public string $providerOperationId,
        public ProviderOperationStatus $normalizedStatus,
        public string $providerStatus,
        public ReconciliationSource $source,
        public string $sourceReference,
        public DateTimeImmutable $providerObservedAt,
        public DateTimeImmutable $receivedAt,
        public array $evidence,
        public string $idempotencyKey,
        public string $observationHash,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'publicationAttemptId' => $this->publicationAttemptId,
            'providerConnectionId' => $this->providerConnectionId,
            'capabilityEvidenceId' => $this->capabilityEvidenceId,
            'providerId' => $this->providerId,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'publicationStatusObservation.'.$field);
        }

        self::assertPublicIdentifier($this->providerOperationId, 'providerOperationId', 512);
        self::assertPublicIdentifier($this->providerStatus, 'providerStatus', 120);
        self::assertPublicIdentifier($this->sourceReference, 'sourceReference', 191);

        if ($this->providerObservedAt->getOffset() !== 0 || $this->receivedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Publication status observation timestamps must be normalized to UTC.');
        }

        if ($this->providerObservedAt > $this->receivedAt) {
            throw new InvalidArgumentException('Publication status provider timestamp cannot be later than receipt time.');
        }

        CampaignPayloadGuard::assertPublicJson($this->evidence, 'publicationStatusObservation.evidence');
        CampaignPayloadGuard::assertSha256($this->idempotencyKey, 'publicationStatusObservation.idempotencyKey');
        CampaignPayloadGuard::assertSha256($this->observationHash, 'publicationStatusObservation.observationHash');

        if (! hash_equals(CampaignPayloadGuard::hash($this->canonicalPayload()), $this->observationHash)) {
            throw new InvalidArgumentException('Publication status observation hash does not match immutable provenance.');
        }
    }

    /** @param array<string, mixed> $evidence */
    public static function record(
        string $id,
        string $workspaceId,
        string $publicationAttemptId,
        string $providerConnectionId,
        string $capabilityEvidenceId,
        string $providerId,
        string $providerOperationId,
        ProviderOperationStatus $normalizedStatus,
        string $providerStatus,
        ReconciliationSource $source,
        string $sourceReference,
        DateTimeImmutable $providerObservedAt,
        DateTimeImmutable $receivedAt,
        array $evidence = [],
    ): self {
        $idempotencyKey = CampaignPayloadGuard::hash([
            'operation' => 'publication.status.observe',
            'workspace_id' => $workspaceId,
            'publication_attempt_id' => $publicationAttemptId,
            'source' => $source->value,
            'source_reference' => $sourceReference,
        ]);

        $payload = [
            'operation' => 'publication.status.observe',
            'workspace_id' => $workspaceId,
            'publication_attempt_id' => $publicationAttemptId,
            'provider_connection_id' => $providerConnectionId,
            'capability_evidence_id' => $capabilityEvidenceId,
            'provider_id' => $providerId,
            'provider_operation_id' => $providerOperationId,
            'normalized_status' => $normalizedStatus->value,
            'provider_status' => $providerStatus,
            'source' => $source->value,
            'source_reference' => $sourceReference,
            'provider_observed_at' => $providerObservedAt->format(DATE_ATOM),
            'received_at' => $receivedAt->format(DATE_ATOM),
            'evidence' => $evidence,
            'idempotency_key' => $idempotencyKey,
        ];

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            publicationAttemptId: $publicationAttemptId,
            providerConnectionId: $providerConnectionId,
            capabilityEvidenceId: $capabilityEvidenceId,
            providerId: $providerId,
            providerOperationId: $providerOperationId,
            normalizedStatus: $normalizedStatus,
            providerStatus: $providerStatus,
            source: $source,
            sourceReference: $sourceReference,
            providerObservedAt: $providerObservedAt,
            receivedAt: $receivedAt,
            evidence: $evidence,
            idempotencyKey: $idempotencyKey,
            observationHash: CampaignPayloadGuard::hash($payload),
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'operation' => 'publication.status.observe',
            'workspace_id' => $this->workspaceId,
            'publication_attempt_id' => $this->publicationAttemptId,
            'provider_connection_id' => $this->providerConnectionId,
            'capability_evidence_id' => $this->capabilityEvidenceId,
            'provider_id' => $this->providerId,
            'provider_operation_id' => $this->providerOperationId,
            'normalized_status' => $this->normalizedStatus->value,
            'provider_status' => $this->providerStatus,
            'source' => $this->source->value,
            'source_reference' => $this->sourceReference,
            'provider_observed_at' => $this->providerObservedAt->format(DATE_ATOM),
            'received_at' => $this->receivedAt->format(DATE_ATOM),
            'evidence' => $this->evidence,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    private static function assertPublicIdentifier(string $value, string $field, int $maxLength): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Publication status {$field} must be non-empty and at most {$maxLength} characters.");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Publication status {$field} contains forbidden control characters.");
        }
    }
}
