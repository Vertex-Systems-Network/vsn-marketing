<?php

namespace App\Modules\Templates\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class VersionedDefinition
{
    /**
     * @param  list<DependencyReference>  $dependencies
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public DefinitionKind $kind,
        public string $ownerId,
        public ?string $parentVersionId,
        public int $versionNumber,
        public int $schemaVersion,
        public VersionStatus $status,
        public array $dependencies,
        public string $idempotencyKey,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
    ) {
        foreach (['id' => $id, 'workspaceId' => $workspaceId, 'ownerId' => $ownerId, 'idempotencyKey' => $idempotencyKey, 'createdByActorId' => $createdByActorId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Versioned definition {$field} must not be empty.");
            }
        }

        if ($schemaVersion !== 1) {
            throw new InvalidArgumentException("Unsupported template/component schema version: {$schemaVersion}");
        }

        if ($versionNumber < 1 || (($versionNumber === 1) !== ($parentVersionId === null))) {
            throw new InvalidArgumentException('Template/component version lineage is invalid.');
        }

        $seen = [];
        foreach ($dependencies as $dependency) {
            if (($dependency instanceof DependencyReference) === false) {
                throw new InvalidArgumentException('Version dependencies must be DependencyReference instances.');
            }

            if ($dependency->workspaceId !== $workspaceId) {
                throw new InvalidArgumentException('Cross-workspace template/component dependencies are forbidden.');
            }

            if ($dependency->versionId === $id) {
                throw new InvalidArgumentException('A template/component version cannot depend on itself.');
            }

            if (isset($seen[$dependency->key()])) {
                throw new InvalidArgumentException('Duplicate version dependency reference.');
            }

            $seen[$dependency->key()] = true;
        }
    }

    /** @param list<DependencyReference> $dependencies */
    public function fork(
        string $id,
        array $dependencies,
        string $idempotencyKey,
        string $createdByActorId,
        DateTimeImmutable $createdAt,
        VersionStatus $status = VersionStatus::Draft,
    ): self {
        if ($createdAt < $this->createdAt) {
            throw new InvalidArgumentException('Child template/component version cannot precede its parent.');
        }

        return new self(
            id: $id,
            workspaceId: $this->workspaceId,
            kind: $this->kind,
            ownerId: $this->ownerId,
            parentVersionId: $this->id,
            versionNumber: $this->versionNumber + 1,
            schemaVersion: $this->schemaVersion,
            status: $status,
            dependencies: $dependencies,
            idempotencyKey: $idempotencyKey,
            createdByActorId: $createdByActorId,
            createdAt: $createdAt,
        );
    }
}
