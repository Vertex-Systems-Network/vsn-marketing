<?php

namespace App\Modules\Content\Domain\Brand;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BrandVersion
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<BrandStyleToken>  $styleTokens
     * @param  array<string, mixed>  $identityMetadata
     * @param  list<string>  $assetReferences
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $auditProvenance
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $brandKitId,
        public ?string $parentVersionId,
        public int $versionNumber,
        public int $schemaVersion,
        public BrandVersionStatus $status,
        public array $styleTokens,
        public array $identityMetadata,
        public array $assetReferences,
        public array $defaults,
        public string $createdByActorId,
        public array $auditProvenance,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'brandKitId' => $this->brandKitId,
            'createdByActorId' => $this->createdByActorId,
            'idempotencyKey' => $this->idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Brand version {$field} must not be empty.");
            }
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported brand version schema version: {$this->schemaVersion}");
        }

        if ($this->versionNumber < 1 || (($this->versionNumber === 1) !== ($this->parentVersionId === null))) {
            throw new InvalidArgumentException('Brand version lineage is invalid.');
        }

        if ($this->parentVersionId !== null && trim($this->parentVersionId) === '') {
            throw new InvalidArgumentException('Brand version parent id must be null or non-empty.');
        }

        if (mb_strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Brand version idempotency key must not exceed 191 characters.');
        }

        $tokenKeys = [];
        foreach ($this->styleTokens as $token) {
            if ($token instanceof BrandStyleToken === false) {
                throw new InvalidArgumentException('Brand style tokens must contain BrandStyleToken values.');
            }

            if (isset($tokenKeys[$token->key])) {
                throw new InvalidArgumentException("Duplicate brand style token key: {$token->key}");
            }

            $tokenKeys[$token->key] = true;
        }

        self::assertStableReferences($this->assetReferences);
        self::assertPublicJson($this->identityMetadata, 'identityMetadata');
        self::assertPublicJson($this->defaults, 'defaults');
        self::assertPublicJson($this->auditProvenance, 'auditProvenance');
    }

    /**
     * @param  list<BrandStyleToken>  $styleTokens
     * @param  array<string, mixed>  $identityMetadata
     * @param  list<string>  $assetReferences
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $auditProvenance
     */
    public static function initialFor(
        BrandKit $brandKit,
        string $id,
        array $styleTokens,
        array $identityMetadata,
        array $assetReferences,
        array $defaults,
        string $createdByActorId,
        array $auditProvenance,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
        BrandVersionStatus $status = BrandVersionStatus::Draft,
    ): self {
        return new self(
            id: $id,
            workspaceId: $brandKit->workspaceId,
            brandKitId: $brandKit->id,
            parentVersionId: null,
            versionNumber: 1,
            schemaVersion: self::SCHEMA_VERSION,
            status: $status,
            styleTokens: $styleTokens,
            identityMetadata: $identityMetadata,
            assetReferences: $assetReferences,
            defaults: $defaults,
            createdByActorId: $createdByActorId,
            auditProvenance: $auditProvenance,
            idempotencyKey: $idempotencyKey,
            createdAt: $createdAt,
        );
    }

    /**
     * @param  list<BrandStyleToken>  $styleTokens
     * @param  array<string, mixed>  $identityMetadata
     * @param  list<string>  $assetReferences
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $auditProvenance
     */
    public function fork(
        string $id,
        array $styleTokens,
        array $identityMetadata,
        array $assetReferences,
        array $defaults,
        string $createdByActorId,
        array $auditProvenance,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
        BrandVersionStatus $status = BrandVersionStatus::Draft,
    ): self {
        if ($createdAt < $this->createdAt) {
            throw new InvalidArgumentException('Child brand version cannot precede its parent.');
        }

        return new self(
            id: $id,
            workspaceId: $this->workspaceId,
            brandKitId: $this->brandKitId,
            parentVersionId: $this->id,
            versionNumber: $this->versionNumber + 1,
            schemaVersion: self::SCHEMA_VERSION,
            status: $status,
            styleTokens: $styleTokens,
            identityMetadata: $identityMetadata,
            assetReferences: $assetReferences,
            defaults: $defaults,
            createdByActorId: $createdByActorId,
            auditProvenance: $auditProvenance,
            idempotencyKey: $idempotencyKey,
            createdAt: $createdAt,
        );
    }

    /** @return array<string, string|int|float|bool> */
    public function styleTokenMap(): array
    {
        $styleTokens = $this->styleTokens;
        usort(
            $styleTokens,
            static fn (BrandStyleToken $left, BrandStyleToken $right): int => $left->key <=> $right->key,
        );

        $resolved = [];

        foreach ($styleTokens as $token) {
            $resolved[$token->key] = $token->value;
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $styleTokens = $this->styleTokens;
        usort(
            $styleTokens,
            static fn (BrandStyleToken $left, BrandStyleToken $right): int => $left->key <=> $right->key,
        );

        $assetReferences = $this->assetReferences;
        sort($assetReferences, SORT_STRING);

        return [
            'schema_version' => $this->schemaVersion,
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'brand_kit_id' => $this->brandKitId,
            'parent_version_id' => $this->parentVersionId,
            'version_number' => $this->versionNumber,
            'status' => $this->status->value,
            'style_tokens' => array_map(
                static fn (BrandStyleToken $token): array => $token->toArray(),
                $styleTokens,
            ),
            'identity_metadata' => $this->identityMetadata,
            'asset_references' => $assetReferences,
            'defaults' => $this->defaults,
        ];
    }

    /** @param  list<string>  $references */
    private static function assertStableReferences(array $references): void
    {
        $seen = [];

        foreach ($references as $reference) {
            if (trim($reference) === '') {
                throw new InvalidArgumentException('Brand asset reference must not be empty.');
            }

            if (mb_strlen($reference) > 191) {
                throw new InvalidArgumentException('Brand asset reference must not exceed 191 characters.');
            }

            if (isset($seen[$reference])) {
                throw new InvalidArgumentException("Duplicate brand asset reference: {$reference}");
            }

            $seen[$reference] = true;
        }
    }

    private static function assertPublicJson(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_finite($value) === false) {
                throw new InvalidArgumentException("Brand metadata number must be finite: {$path}");
            }

            return;
        }

        if (is_string($value)) {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException("Brand metadata contains forbidden control characters: {$path}");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $segment = (string) $key;

                if (
                    is_string($key)
                    && preg_match(
                        '/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key|upload[_-]?id|provider[_-]?template[_-]?id|provider[_-]?asset[_-]?id/i',
                        $key,
                    ) === 1
                ) {
                    throw new InvalidArgumentException("Sensitive or provider-owned brand metadata key is forbidden: {$path}.{$segment}");
                }

                self::assertPublicJson($nested, $path.'.'.$segment);
            }

            return;
        }

        throw new InvalidArgumentException("Brand metadata must be JSON-compatible: {$path}");
    }
}
