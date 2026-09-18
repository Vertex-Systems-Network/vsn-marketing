<?php

namespace App\Modules\Templates\Domain;

use InvalidArgumentException;

final class DependencyGraph
{
    /** @var array<string, list<string>> */
    private array $edges = [];

    /**
     * @param  list<VersionedDefinition>  $versions
     */
    public function __construct(array $versions)
    {
        $known = [];
        foreach ($versions as $version) {
            if (($version instanceof VersionedDefinition) === false) {
                throw new InvalidArgumentException('Dependency graph nodes must be VersionedDefinition instances.');
            }
            $known[$version->id] = $version;
        }

        foreach ($versions as $version) {
            $this->edges[$version->id] = [];
            foreach ($version->dependencies as $dependency) {
                if (isset($known[$dependency->versionId])) {
                    $this->edges[$version->id][] = $dependency->versionId;
                }
            }
        }

        $visiting = [];
        $visited = [];
        foreach (array_keys($this->edges) as $id) {
            $this->visit($id, $visiting, $visited);
        }
    }

    /** @param array<string, true> $visiting @param array<string, true> $visited */
    private function visit(string $id, array &$visiting, array &$visited): void
    {
        if (isset($visited[$id])) {
            return;
        }

        if (isset($visiting[$id])) {
            throw new InvalidArgumentException("Template/component dependency cycle detected at version {$id}.");
        }

        $visiting[$id] = true;
        foreach ($this->edges[$id] ?? [] as $target) {
            $this->visit($target, $visiting, $visited);
        }
        unset($visiting[$id]);
        $visited[$id] = true;
    }
}
