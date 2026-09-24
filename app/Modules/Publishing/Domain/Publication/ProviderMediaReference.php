<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProviderMediaReference
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $publicationAttemptId,
        public string $snapshotId,
        public string $assetId,
        public string $assetOriginalId,
        public ?string $assetVariantId,
        public ProviderMediaAssetReferenceKind $assetReferenceKind,
        public string $canonicalAssetReferenceId,
        public string $assetContentSha256,
        public string $providerConnectionId,
        public string $capabilityEvidenceId,
        public string $providerId,
        public ProviderMediaReferenceKind $providerReferenceKind,
        public string $providerReference,
        public ProviderMediaReferenceState $state,
        public int $stateVersion,
        public ?DateTimeImmutable $expiresAt,
        public string $idempotencyKey,
        public string $referenceHash,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'publicationAttemptId' => $this->publicationAttemptId,
            'snapshotId' => $this->snapshotId,
            'assetId' => $this->assetId,
            'assetOriginalId' => $this->assetOriginalId,
            'canonicalAssetReferenceId' => $this->canonicalAssetReferenceId,
            'providerConnectionId' => $this->providerConnectionId,
            'capabilityEvidenceId' => $this->capabilityEvidenceId,
            'providerId' => $this->providerId,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'providerMediaReference.'.$field);
        }

        if ($this->assetVariantId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->assetVariantId, 'providerMediaReference.assetVariantId');
        }

        if (
            ($this->assetReferenceKind === ProviderMediaAssetReferenceKind::Original
                && ($this->assetVariantId !== null || $this->canonicalAssetReferenceId !== $this->assetOriginalId))
            || ($this->assetReferenceKind === ProviderMediaAssetReferenceKind::Variant
                && ($this->assetVariantId === null || $this->canonicalAssetReferenceId !== $this->assetVariantId))
        ) {
            throw new InvalidArgumentException('Provider media canonical asset reference kind does not match immutable asset identity.');
        }

        CampaignPayloadGuard::assertSha256($this->assetContentSha256, 'providerMediaReference.assetContentSha256');
        CampaignPayloadGuard::assertSha256($this->idempotencyKey, 'providerMediaReference.idempotencyKey');
        CampaignPayloadGuard::assertSha256($this->referenceHash, 'providerMediaReference.referenceHash');

        $reference = trim($this->providerReference);
        if ($reference === '' || mb_strlen($reference) > 512) {
            throw new InvalidArgumentException('Provider media reference must be a non-empty opaque identifier up to 512 characters.');
        }

        if (
            str_starts_with($reference, '//')
            || preg_match('/^(?:https?|ftp|file|data):/i', $reference) === 1
            || str_contains($reference, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1
        ) {
            throw new InvalidArgumentException('Provider media reference must be opaque and cannot be a remote-media URL or unsafe control value.');
        }

        if ($this->stateVersion < 1) {
            throw new InvalidArgumentException('Provider media reference state version must be at least 1.');
        }

        if ($this->createdAt->getOffset() !== 0 || $this->updatedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Provider media reference timestamps must be normalized to UTC.');
        }

        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Provider media reference updated_at cannot precede created_at.');
        }

        if ($this->expiresAt !== null) {
            if ($this->expiresAt->getOffset() !== 0) {
                throw new InvalidArgumentException('Provider media reference expiry must be normalized to UTC.');
            }

            if ($this->expiresAt <= $this->createdAt) {
                throw new InvalidArgumentException('Provider media reference expiry must be later than its observation time.');
            }
        }

        if (! hash_equals(CampaignPayloadGuard::hash($this->canonicalIdentityPayload()), $this->referenceHash)) {
            throw new InvalidArgumentException('Provider media reference hash does not match canonical derivative identity.');
        }
    }

    public static function register(
        string $id,
        string $workspaceId,
        string $publicationAttemptId,
        string $snapshotId,
        string $assetId,
        string $assetOriginalId,
        ?string $assetVariantId,
        ProviderMediaAssetReferenceKind $assetReferenceKind,
        string $canonicalAssetReferenceId,
        string $assetContentSha256,
        string $providerConnectionId,
        string $capabilityEvidenceId,
        string $providerId,
        ProviderMediaReferenceKind $providerReferenceKind,
        string $providerReference,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): self {
        $idempotencyKey = CampaignPayloadGuard::hash([
            'operation' => 'publication.media.reference',
            'workspace_id' => $workspaceId,
            'publication_attempt_id' => $publicationAttemptId,
            'canonical_asset_reference_id' => $canonicalAssetReferenceId,
            'provider_reference_kind' => $providerReferenceKind->value,
        ]);

        $identity = [
            'operation' => 'publication.media.reference',
            'workspace_id' => $workspaceId,
            'publication_attempt_id' => $publicationAttemptId,
            'snapshot_id' => $snapshotId,
            'asset_id' => $assetId,
            'asset_original_id' => $assetOriginalId,
            'asset_variant_id' => $assetVariantId,
            'asset_reference_kind' => $assetReferenceKind->value,
            'canonical_asset_reference_id' => $canonicalAssetReferenceId,
            'asset_content_sha256' => $assetContentSha256,
            'provider_connection_id' => $providerConnectionId,
            'capability_evidence_id' => $capabilityEvidenceId,
            'provider_id' => $providerId,
            'provider_reference_kind' => $providerReferenceKind->value,
            'provider_reference' => $providerReference,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'idempotency_key' => $idempotencyKey,
        ];

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            publicationAttemptId: $publicationAttemptId,
            snapshotId: $snapshotId,
            assetId: $assetId,
            assetOriginalId: $assetOriginalId,
            assetVariantId: $assetVariantId,
            assetReferenceKind: $assetReferenceKind,
            canonicalAssetReferenceId: $canonicalAssetReferenceId,
            assetContentSha256: $assetContentSha256,
            providerConnectionId: $providerConnectionId,
            capabilityEvidenceId: $capabilityEvidenceId,
            providerId: $providerId,
            providerReferenceKind: $providerReferenceKind,
            providerReference: $providerReference,
            state: ProviderMediaReferenceState::Pending,
            stateVersion: 1,
            expiresAt: $expiresAt,
            idempotencyKey: $idempotencyKey,
            referenceHash: CampaignPayloadGuard::hash($identity),
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );
    }

    public function transitionTo(ProviderMediaReferenceState $next, DateTimeImmutable $at): self
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new InvalidArgumentException(
                "Provider media reference cannot transition from {$this->state->value} to {$next->value}.",
            );
        }

        if ($at->getOffset() !== 0 || $at <= $this->updatedAt) {
            throw new InvalidArgumentException('Provider media reference transitions require a later UTC timestamp.');
        }

        if ($this->expiresAt !== null && $at >= $this->expiresAt && $next !== ProviderMediaReferenceState::Expired) {
            throw new InvalidArgumentException('Expired provider media references can only transition to expired.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            publicationAttemptId: $this->publicationAttemptId,
            snapshotId: $this->snapshotId,
            assetId: $this->assetId,
            assetOriginalId: $this->assetOriginalId,
            assetVariantId: $this->assetVariantId,
            assetReferenceKind: $this->assetReferenceKind,
            canonicalAssetReferenceId: $this->canonicalAssetReferenceId,
            assetContentSha256: $this->assetContentSha256,
            providerConnectionId: $this->providerConnectionId,
            capabilityEvidenceId: $this->capabilityEvidenceId,
            providerId: $this->providerId,
            providerReferenceKind: $this->providerReferenceKind,
            providerReference: $this->providerReference,
            state: $next,
            stateVersion: $this->stateVersion + 1,
            expiresAt: $this->expiresAt,
            idempotencyKey: $this->idempotencyKey,
            referenceHash: $this->referenceHash,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalIdentityPayload(): array
    {
        return [
            'operation' => 'publication.media.reference',
            'workspace_id' => $this->workspaceId,
            'publication_attempt_id' => $this->publicationAttemptId,
            'snapshot_id' => $this->snapshotId,
            'asset_id' => $this->assetId,
            'asset_original_id' => $this->assetOriginalId,
            'asset_variant_id' => $this->assetVariantId,
            'asset_reference_kind' => $this->assetReferenceKind->value,
            'canonical_asset_reference_id' => $this->canonicalAssetReferenceId,
            'asset_content_sha256' => $this->assetContentSha256,
            'provider_connection_id' => $this->providerConnectionId,
            'capability_evidence_id' => $this->capabilityEvidenceId,
            'provider_id' => $this->providerId,
            'provider_reference_kind' => $this->providerReferenceKind->value,
            'provider_reference' => $this->providerReference,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
