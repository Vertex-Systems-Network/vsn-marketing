<?php

namespace App\Modules\Content\Domain\Version;

use App\Modules\Content\Domain\Document\ContentDocument;
use App\Modules\Content\Domain\Document\ContentTree;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ContentVersion
{
    public const int SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $auditProvenance */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $documentId,
        public ?string $parentVersionId,
        public int $versionNumber,
        public int $schemaVersion,
        public ContentVersionStatus $status,
        public ContentTree $tree,
        public string $createdByActorId,
        public array $auditProvenance,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
    ) {
        foreach (['id' => $this->id, 'workspaceId' => $this->workspaceId, 'documentId' => $this->documentId, 'createdByActorId' => $this->createdByActorId, 'idempotencyKey' => $this->idempotencyKey] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Content version {$field} must not be empty.");
            }
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION || $this->tree->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Content version and tree schema versions must use the supported canonical schema.');
        }

        if ($this->versionNumber < 1) {
            throw new InvalidArgumentException('Content version number must be at least 1.');
        }

        if (($this->versionNumber === 1) !== ($this->parentVersionId === null)) {
            throw new InvalidArgumentException('Only the first content version may omit parent lineage.');
        }

        if ($this->parentVersionId !== null && trim($this->parentVersionId) === '') {
            throw new InvalidArgumentException('Content version parent id must be null or a non-empty identifier.');
        }

        if (mb_strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Content version idempotency key must not exceed 191 characters.');
        }

        self::assertPublicProvenance($this->auditProvenance, 'auditProvenance');
    }

    /** @param array<string, mixed> $auditProvenance */
    public static function initialFor(
        ContentDocument $document,
        string $id,
        ContentTree $tree,
        string $createdByActorId,
        array $auditProvenance,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
        ContentVersionStatus $status = ContentVersionStatus::Draft,
    ): self {
        return new self(
            id: $id,
            workspaceId: $document->workspaceId,
            documentId: $document->id,
            parentVersionId: null,
            versionNumber: 1,
            schemaVersion: self::SCHEMA_VERSION,
            status: $status,
            tree: $tree,
            createdByActorId: $createdByActorId,
            auditProvenance: $auditProvenance,
            idempotencyKey: $idempotencyKey,
            createdAt: $createdAt,
        );
    }

    /** @param array<string, mixed> $auditProvenance */
    public function fork(
        string $id,
        ContentTree $tree,
        string $createdByActorId,
        array $auditProvenance,
        string $idempotencyKey,
        DateTimeImmutable $createdAt,
        ContentVersionStatus $status = ContentVersionStatus::Draft,
    ): self {
        if ($createdAt < $this->createdAt) {
            throw new InvalidArgumentException('Child content version creation time must not precede its parent.');
        }

        return new self(
            id: $id,
            workspaceId: $this->workspaceId,
            documentId: $this->documentId,
            parentVersionId: $this->id,
            versionNumber: $this->versionNumber + 1,
            schemaVersion: self::SCHEMA_VERSION,
            status: $status,
            tree: $tree,
            createdByActorId: $createdByActorId,
            auditProvenance: $auditProvenance,
            idempotencyKey: $idempotencyKey,
            createdAt: $createdAt,
        );
    }

    /** @param array<string, mixed> $value */
    private static function assertPublicProvenance(array $value, string $path): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key/i', $key)) {
                throw new InvalidArgumentException("Sensitive content version provenance key is forbidden: {$path}.{$key}");
            }

            if (is_array($nested)) {
                self::assertPublicProvenance($nested, $path.'.'.(string) $key);
            }
        }
    }
}
