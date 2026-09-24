<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicationOperationAuthorization
{
    public string $workspaceId;

    public string $publicationAttemptId;

    public PublicationOperation $operation;

    public string $providerCapabilityOperation;

    public string $providerId;

    public string $providerConnectionId;

    public string $originalCapabilityEvidenceId;

    public string $currentCapabilityEvidenceId;

    public string $currentCapabilitySourceVersion;

    public string $connectionSourceVersion;

    public string $attemptHash;

    public string $projectionObservationHash;

    public int $projectionVersion;

    public string $providerOperationId;

    public DateTimeImmutable $authorizedAt;

    public string $authorizationHash;

    public function __construct(
        string $workspaceId,
        string $publicationAttemptId,
        PublicationOperation $operation,
        string $providerCapabilityOperation,
        string $providerId,
        string $providerConnectionId,
        string $originalCapabilityEvidenceId,
        string $currentCapabilityEvidenceId,
        string $currentCapabilitySourceVersion,
        string $connectionSourceVersion,
        string $attemptHash,
        string $projectionObservationHash,
        int $projectionVersion,
        string $providerOperationId,
        DateTimeImmutable $authorizedAt,
    ) {
        foreach ([
            'workspaceId' => $workspaceId,
            'publicationAttemptId' => $publicationAttemptId,
            'providerId' => $providerId,
            'providerConnectionId' => $providerConnectionId,
            'originalCapabilityEvidenceId' => $originalCapabilityEvidenceId,
            'currentCapabilityEvidenceId' => $currentCapabilityEvidenceId,
            'currentCapabilitySourceVersion' => $currentCapabilitySourceVersion,
            'connectionSourceVersion' => $connectionSourceVersion,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'publicationOperationAuthorization.'.$field);
        }

        if ($providerCapabilityOperation !== $operation->providerCapabilityOperation()) {
            throw new InvalidArgumentException('Publication operation authorization capability operation does not match the requested operation.');
        }

        CampaignPayloadGuard::assertIdentifier(
            $providerCapabilityOperation,
            'publicationOperationAuthorization.providerCapabilityOperation',
        );
        CampaignPayloadGuard::assertSha256($attemptHash, 'publicationOperationAuthorization.attemptHash');
        CampaignPayloadGuard::assertSha256(
            $projectionObservationHash,
            'publicationOperationAuthorization.projectionObservationHash',
        );

        if ($projectionVersion < 1) {
            throw new InvalidArgumentException('Publication operation authorization projection version must be at least 1.');
        }

        if (trim($providerOperationId) === '' || mb_strlen($providerOperationId) > 512) {
            throw new InvalidArgumentException('Publication operation authorization provider operation ID is invalid.');
        }

        if ($authorizedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Publication operation authorization timestamp must be normalized to UTC.');
        }

        $this->workspaceId = $workspaceId;
        $this->publicationAttemptId = $publicationAttemptId;
        $this->operation = $operation;
        $this->providerCapabilityOperation = $providerCapabilityOperation;
        $this->providerId = $providerId;
        $this->providerConnectionId = $providerConnectionId;
        $this->originalCapabilityEvidenceId = $originalCapabilityEvidenceId;
        $this->currentCapabilityEvidenceId = $currentCapabilityEvidenceId;
        $this->currentCapabilitySourceVersion = $currentCapabilitySourceVersion;
        $this->connectionSourceVersion = $connectionSourceVersion;
        $this->attemptHash = $attemptHash;
        $this->projectionObservationHash = $projectionObservationHash;
        $this->projectionVersion = $projectionVersion;
        $this->providerOperationId = $providerOperationId;
        $this->authorizedAt = $authorizedAt;
        $this->authorizationHash = CampaignPayloadGuard::hash($this->canonicalPayload());
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'operation' => 'publication.operation.authorize',
            'workspace_id' => $this->workspaceId,
            'publication_attempt_id' => $this->publicationAttemptId,
            'requested_operation' => $this->operation->value,
            'provider_capability_operation' => $this->providerCapabilityOperation,
            'provider_id' => $this->providerId,
            'provider_connection_id' => $this->providerConnectionId,
            'original_capability_evidence_id' => $this->originalCapabilityEvidenceId,
            'current_capability_evidence_id' => $this->currentCapabilityEvidenceId,
            'current_capability_source_version' => $this->currentCapabilitySourceVersion,
            'connection_source_version' => $this->connectionSourceVersion,
            'attempt_hash' => $this->attemptHash,
            'projection_observation_hash' => $this->projectionObservationHash,
            'projection_version' => $this->projectionVersion,
            'provider_operation_id' => $this->providerOperationId,
            'authorized_at' => $this->authorizedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
