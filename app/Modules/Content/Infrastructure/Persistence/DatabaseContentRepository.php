<?php

namespace App\Modules\Content\Infrastructure\Persistence;

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Domain\Document\CanonicalNodeType;
use App\Modules\Content\Domain\Document\ContentDocument;
use App\Modules\Content\Domain\Document\ContentLifecycle;
use App\Modules\Content\Domain\Document\ContentNode;
use App\Modules\Content\Domain\Document\ContentTree;
use App\Modules\Content\Domain\Version\ContentVersion;
use App\Modules\Content\Domain\Version\ContentVersionStatus;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class DatabaseContentRepository
{
    public function __construct(
        private DatabaseManager $database,
        private CanonicalJsonHasher $hasher,
    ) {}

    public function createDocument(ContentDocument $document): ContentDocument
    {
        return $this->database->connection()->transaction(function () use ($document): ContentDocument {
            $existing = $this->database->connection()->table('content_documents')
                ->where('id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                if ((string) $existing->workspace_id !== $document->workspaceId) {
                    throw new AuthorizationException('Content document access denied.');
                }

                $stored = $this->hydrateDocument($existing);
                $this->assertDocumentReplay($stored, $document);

                return $stored;
            }

            try {
                $this->database->connection()->table('content_documents')->insert([
                    'id' => $document->id,
                    'workspace_id' => $document->workspaceId,
                    'name' => $document->name,
                    'lifecycle' => $document->lifecycle->value,
                    'created_by_actor_id' => $document->createdByActorId,
                    'audit_provenance' => $this->encode($document->auditProvenance),
                    'created_at' => $document->createdAt,
                    'updated_at' => $document->updatedAt,
                ]);
            } catch (QueryException $exception) {
                $winner = $this->database->connection()->table('content_documents')
                    ->where('id', $document->id)
                    ->first();

                if ($winner instanceof stdClass) {
                    if ((string) $winner->workspace_id !== $document->workspaceId) {
                        throw new AuthorizationException('Content document access denied.', previous: $exception);
                    }

                    $stored = $this->hydrateDocument($winner);
                    $this->assertDocumentReplay($stored, $document);

                    return $stored;
                }

                throw $exception;
            }

            return $document;
        });
    }

    public function updateDocument(ContentDocument $document, ?DateTimeImmutable $expectedUpdatedAt): ContentDocument
    {
        return $this->database->connection()->transaction(function () use ($document, $expectedUpdatedAt): ContentDocument {
            $row = $this->database->connection()->table('content_documents')
                ->where('workspace_id', $document->workspaceId)
                ->where('id', $document->id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                $this->denyIfForeignDocumentIdExists($document->workspaceId, $document->id);
                throw new InvalidArgumentException('Content document does not exist in this workspace.');
            }

            $stored = $this->hydrateDocument($row);
            $this->assertDocumentIdentity($stored, $document);

            if (! self::sameMoment($stored->updatedAt, $expectedUpdatedAt)) {
                throw new InvalidArgumentException('Content document optimistic concurrency conflict.');
            }

            if ($document->updatedAt === null) {
                throw new InvalidArgumentException('Content document update must advance updatedAt.');
            }

            $currentMoment = $stored->updatedAt ?? $stored->createdAt;
            if ($document->updatedAt <= $currentMoment) {
                throw new InvalidArgumentException('Content document update must advance updatedAt.');
            }

            $updated = $this->database->connection()->table('content_documents')
                ->where('workspace_id', $document->workspaceId)
                ->where('id', $document->id)
                ->where(function ($query) use ($expectedUpdatedAt): void {
                    $expectedUpdatedAt === null
                        ? $query->whereNull('updated_at')
                        : $query->where('updated_at', $expectedUpdatedAt);
                })
                ->update([
                    'lifecycle' => $document->lifecycle->value,
                    'updated_at' => $document->updatedAt,
                ]);

            if ($updated !== 1) {
                throw new InvalidArgumentException('Content document optimistic concurrency conflict.');
            }

            return $document;
        });
    }

    public function findDocument(string $workspaceId, string $documentId): ?ContentDocument
    {
        $row = $this->database->connection()->table('content_documents')
            ->where('workspace_id', $workspaceId)
            ->where('id', $documentId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateDocument($row);
        }

        $this->denyIfForeignDocumentIdExists($workspaceId, $documentId);

        return null;
    }

    public function appendVersion(ContentVersion $version): ContentVersion
    {
        return $this->database->connection()->transaction(function () use ($version): ContentVersion {
            $this->assertDocumentScope($version->workspaceId, $version->documentId);

            $existing = $this->database->connection()->table('content_versions')
                ->where('workspace_id', $version->workspaceId)
                ->where('idempotency_key', $version->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateVersion($existing);
                $this->assertVersionReplay($stored, $version);

                return $stored;
            }

            $this->denyIfForeignVersionIdExists($version->workspaceId, $version->id);
            $this->assertParentLineage($version);

            try {
                $this->database->connection()->table('content_versions')->insert([
                    'id' => $version->id,
                    'workspace_id' => $version->workspaceId,
                    'document_id' => $version->documentId,
                    'parent_version_id' => $version->parentVersionId,
                    'version_number' => $version->versionNumber,
                    'schema_version' => $version->schemaVersion,
                    'status' => $version->status->value,
                    'canonical_tree' => $this->hasher->encode($version->tree->toArray()),
                    'audit_provenance' => $this->encode($version->auditProvenance),
                    'idempotency_key' => $version->idempotencyKey,
                    'created_by_actor_id' => $version->createdByActorId,
                    'created_at' => $version->createdAt,
                ]);
            } catch (QueryException $exception) {
                $winner = $this->database->connection()->table('content_versions')
                    ->where('workspace_id', $version->workspaceId)
                    ->where('idempotency_key', $version->idempotencyKey)
                    ->first();

                if ($winner instanceof stdClass) {
                    $stored = $this->hydrateVersion($winner);
                    $this->assertVersionReplay($stored, $version);

                    return $stored;
                }

                throw $exception;
            }

            return $version;
        });
    }

    public function findVersion(string $workspaceId, string $versionId): ?ContentVersion
    {
        $row = $this->database->connection()->table('content_versions')
            ->where('workspace_id', $workspaceId)
            ->where('id', $versionId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateVersion($row);
        }

        $this->denyIfForeignVersionIdExists($workspaceId, $versionId);

        return null;
    }

    private function assertDocumentScope(string $workspaceId, string $documentId): void
    {
        if ($this->database->connection()->table('content_documents')
            ->where('workspace_id', $workspaceId)
            ->where('id', $documentId)
            ->exists()) {
            return;
        }

        $this->denyIfForeignDocumentIdExists($workspaceId, $documentId);
        throw new InvalidArgumentException('Content document does not exist in this workspace.');
    }

    private function assertParentLineage(ContentVersion $version): void
    {
        if ($version->parentVersionId === null) {
            if ($version->versionNumber !== 1) {
                throw new InvalidArgumentException('Initial content version must use version number 1.');
            }

            return;
        }

        $parent = $this->database->connection()->table('content_versions')
            ->where('workspace_id', $version->workspaceId)
            ->where('id', $version->parentVersionId)
            ->first();

        if (! $parent instanceof stdClass) {
            $this->denyIfForeignVersionIdExists($version->workspaceId, $version->parentVersionId);
            throw new InvalidArgumentException('Content version parent does not exist in this workspace.');
        }

        if ((string) $parent->document_id !== $version->documentId) {
            throw new InvalidArgumentException('Content version parent belongs to a different content document.');
        }

        if ((int) $parent->version_number + 1 !== $version->versionNumber) {
            throw new InvalidArgumentException('Content version lineage must advance exactly one version.');
        }

        if (new DateTimeImmutable((string) $parent->created_at) > $version->createdAt) {
            throw new InvalidArgumentException('Content version creation time must not precede its parent.');
        }
    }

    private function assertDocumentReplay(ContentDocument $stored, ContentDocument $candidate): void
    {
        $this->assertDocumentIdentity($stored, $candidate);

        if (
            $stored->lifecycle !== $candidate->lifecycle
            || ! self::sameMoment($stored->updatedAt, $candidate->updatedAt)
        ) {
            throw new InvalidArgumentException('Content document ID conflicts with different state.');
        }
    }

    private function assertDocumentIdentity(ContentDocument $stored, ContentDocument $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->name !== $candidate->name
            || $stored->createdByActorId !== $candidate->createdByActorId
            || $stored->auditProvenance != $candidate->auditProvenance
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
        ) {
            throw new InvalidArgumentException('Content document immutable identity conflicts with stored state.');
        }
    }

    private function assertVersionReplay(ContentVersion $stored, ContentVersion $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->documentId !== $candidate->documentId
            || $stored->parentVersionId !== $candidate->parentVersionId
            || $stored->versionNumber !== $candidate->versionNumber
            || $stored->schemaVersion !== $candidate->schemaVersion
            || $stored->status !== $candidate->status
            || $stored->createdByActorId !== $candidate->createdByActorId
            || $stored->auditProvenance != $candidate->auditProvenance
            || $this->hasher->hash($stored->tree->toArray()) !== $this->hasher->hash($candidate->tree->toArray())
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
        ) {
            throw new InvalidArgumentException('Content version idempotency key conflicts with different immutable content.');
        }
    }

    private function denyIfForeignDocumentIdExists(string $workspaceId, string $documentId): void
    {
        if ($this->database->connection()->table('content_documents')
            ->where('id', $documentId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Content document access denied.');
        }
    }

    private function denyIfForeignVersionIdExists(string $workspaceId, string $versionId): void
    {
        if ($this->database->connection()->table('content_versions')
            ->where('id', $versionId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Content version access denied.');
        }
    }

    private function hydrateDocument(stdClass $row): ContentDocument
    {
        return new ContentDocument(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            name: (string) $row->name,
            lifecycle: ContentLifecycle::from((string) $row->lifecycle),
            createdByActorId: (string) $row->created_by_actor_id,
            auditProvenance: $this->decodeArray($row->audit_provenance, 'content_documents.audit_provenance'),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: $row->updated_at === null ? null : new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function hydrateVersion(stdClass $row): ContentVersion
    {
        $tree = $this->decodeArray($row->canonical_tree, 'content_versions.canonical_tree');

        return new ContentVersion(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            documentId: (string) $row->document_id,
            parentVersionId: $row->parent_version_id === null ? null : (string) $row->parent_version_id,
            versionNumber: (int) $row->version_number,
            schemaVersion: (int) $row->schema_version,
            status: ContentVersionStatus::from((string) $row->status),
            tree: $this->hydrateTree($tree),
            createdByActorId: (string) $row->created_by_actor_id,
            auditProvenance: $this->decodeArray($row->audit_provenance, 'content_versions.audit_provenance'),
            idempotencyKey: (string) $row->idempotency_key,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function hydrateTree(array $payload): ContentTree
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $root = $payload['root'] ?? null;

        if (! is_int($schemaVersion) || ! is_array($root)) {
            throw new UnexpectedValueException('Stored canonical content tree has an invalid envelope.');
        }

        return new ContentTree(
            root: $this->hydrateNode($root),
            schemaVersion: $schemaVersion,
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function hydrateNode(array $payload): ContentNode
    {
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $properties = $payload['properties'] ?? [];
        $children = $payload['children'] ?? [];

        if (! is_string($id) || ! is_string($type) || ! is_array($properties) || ! is_array($children)) {
            throw new UnexpectedValueException('Stored canonical content node is malformed.');
        }

        $hydratedChildren = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new UnexpectedValueException('Stored canonical content child node is malformed.');
            }

            $hydratedChildren[] = $this->hydrateNode($child);
        }

        return new ContentNode(
            nodeId: $id,
            type: CanonicalNodeType::from($type),
            properties: $properties,
            children: $hydratedChildren,
        );
    }

    /** @param  array<string, mixed>  $value */
    private function encode(array $value): string
    {
        return $this->hasher->encode($value);
    }

    /** @return array<string, mixed> */
    private function decodeArray(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException("Stored {$field} must be JSON.");
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("Stored {$field} contains invalid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("Stored {$field} must decode to an array.");
        }

        return $decoded;
    }

    private static function sameMoment(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left == $right;
    }
}
