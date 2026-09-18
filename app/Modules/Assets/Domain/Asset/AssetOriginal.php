<?php

namespace App\Modules\Assets\Domain\Asset;

use App\Modules\Assets\Domain\Ingestion\IngestionObservation;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AssetOriginal
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $sourceMetadata
     * @param  array<string, mixed>  $rightsMetadata
     * @param  array<string, mixed>  $auditProvenance
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $assetId,
        public ?string $parentOriginalId,
        public int $versionNumber,
        public int $schemaVersion,
        public IngestionObservation $observation,
        public string $storageDisk,
        public string $storageKey,
        public array $sourceMetadata,
        public array $rightsMetadata,
        public string $createdByActorId,
        public array $auditProvenance,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'assetId' => $this->assetId,
            'storageDisk' => $this->storageDisk,
            'storageKey' => $this->storageKey,
            'createdByActorId' => $this->createdByActorId,
            'idempotencyKey' => $this->idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Asset original {$field} must not be empty.");
            }
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported asset original schema version.');
        }

        if ($this->versionNumber < 1) {
            throw new InvalidArgumentException('Asset original version number must be at least 1.');
        }

        if (($this->versionNumber === 1) !== ($this->parentOriginalId === null)) {
            throw new InvalidArgumentException('Only the first asset original may omit parent lineage.');
        }

        if ($this->parentOriginalId !== null && trim($this->parentOriginalId) === '') {
            throw new InvalidArgumentException('Asset original parent id must be null or non-empty.');
        }

        if (mb_strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Asset original idempotency key must not exceed 191 characters.');
        }

        if (mb_strlen($this->storageDisk) > 64) {
            throw new InvalidArgumentException('Asset original storage disk must not exceed 64 characters.');
        }

        if (mb_strlen($this->storageKey) > 1024) {
            throw new InvalidArgumentException('Asset original storage key must not exceed 1024 characters.');
        }

        if (
            str_starts_with($this->storageKey, '/')
            || str_contains($this->storageKey, '\\')
            || preg_match('#(^|/)\.\.?(/|$)#', $this->storageKey) === 1
            || str_contains($this->storageKey, "\0")
        ) {
            throw new InvalidArgumentException('Asset original storage key contains an unsafe path.');
        }

        CanonicalAsset::assertPublicMetadata($this->sourceMetadata, 'sourceMetadata');
        CanonicalAsset::assertPublicMetadata($this->rightsMetadata, 'rightsMetadata');
        CanonicalAsset::assertPublicMetadata($this->auditProvenance, 'auditProvenance');
    }

    /**
     * @param  array<string, mixed>  $sourceMetadata
     * @param  array<string, mixed>  $rightsMetadata
     * @param  array<string, mixed>  $auditProvenance
     */
    public static function initialFor(
        CanonicalAsset $asset,
        string $id,
        IngestionObservation $observation,
        string $storageDisk,
        string $storageKey,
        array $sourceMetadata,
        array $rightsMetadata,
        string $createdByActorId,
        array $auditProvenance,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            workspaceId: $asset->workspaceId,
            assetId: $asset->id,
            parentOriginalId: null,
            versionNumber: 1,
            schemaVersion: self::SCHEMA_VERSION,
            observation: $observation,
            storageDisk: $storageDisk,
            storageKey: $storageKey,
            sourceMetadata: $sourceMetadata,
            rightsMetadata: $rightsMetadata,
            createdByActorId: $createdByActorId,
            auditProvenance: $auditProvenance,
            idempotencyKey: $idempotencyKey,
            createdAt: $createdAt,
        );
    }

    /**
     * Replacement creates a new immutable original instead of mutating this one.
     *
     * @param  array<string, mixed>  $sourceMetadata
     * @param  array<string, mixed>  $rightsMetadata
     * @param  array<string, mixed>  $auditProvenance
     */
    public function replaceWith(
        string $id,
        IngestionObservation $observation,
        string $storageDisk,
        string $storageKey,
        array $sourceMetadata,
        array $rightsMetadata,
        string $createdByActorId,
        array $auditProvenance,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
    ): self {
        if ($createdAt < $this->createdAt) {
            throw new InvalidArgumentException('Replacement asset original cannot precede its parent.');
        }

        return new self(
            id: $id,
            workspaceId: $this->workspaceId,
            assetId: $this->assetId,
            parentOriginalId: $this->id,
            versionNumber: $this->versionNumber + 1,
            schemaVersion: self::SCHEMA_VERSION,
            observation: $observation,
            storageDisk: $storageDisk,
            storageKey: $storageKey,
            sourceMetadata: $sourceMetadata,
            rightsMetadata: $rightsMetadata,
            createdByActorId: $createdByActorId,
            auditProvenance: $auditProvenance,
            idempotencyKey: $idempotencyKey,
            createdAt: $createdAt,
        );
    }
}
