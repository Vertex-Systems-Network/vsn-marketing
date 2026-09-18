<?php

namespace App\Modules\Templates\Domain;

use InvalidArgumentException;

final readonly class DependencyReference
{
    public function __construct(
        public string $workspaceId,
        public DependencyKind $kind,
        public string $versionId,
    ) {
        if (trim($workspaceId) === '' || trim($versionId) === '') {
            throw new InvalidArgumentException('Dependency workspace and version identifiers must not be empty.');
        }
    }

    public function key(): string
    {
        return $this->kind->value.':'.$this->versionId;
    }
}
