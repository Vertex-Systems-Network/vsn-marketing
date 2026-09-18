<?php

namespace App\Modules\Content\Application\DependencyResolution;

use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\VersionedDefinition;
use InvalidArgumentException;

final class DependencyResolver
{
    /** @param  list<VersionedDefinition>  $availableVersions */
    public function resolve(VersionedDefinition $root, array $availableVersions): ResolvedDependencySet
    {
        $catalog = [];

        foreach ($availableVersions as $version) {
            if (isset($catalog[$version->id])) {
                throw new InvalidArgumentException("Duplicate dependency catalog version id: {$version->id}");
            }

            $catalog[$version->id] = $version;
        }

        $visiting = [$root->id => true];
        $visited = [];
        $resolved = [];

        $dependencies = $root->dependencies;
        usort(
            $dependencies,
            static fn (DependencyReference $left, DependencyReference $right): int => $left->key() <=> $right->key(),
        );

        foreach ($dependencies as $dependency) {
            $this->visit(
                workspaceId: $root->workspaceId,
                dependency: $dependency,
                catalog: $catalog,
                visiting: $visiting,
                visited: $visited,
                resolved: $resolved,
            );
        }

        return new ResolvedDependencySet($resolved);
    }

    /**
     * @param  array<string, VersionedDefinition>  $catalog
     * @param  array<string, true>  $visiting
     * @param  array<string, true>  $visited
     * @param  list<VersionedDefinition>  $resolved
     */
    private function visit(
        string $workspaceId,
        DependencyReference $dependency,
        array $catalog,
        array &$visiting,
        array &$visited,
        array &$resolved,
    ): void {
        if ($dependency->workspaceId !== $workspaceId) {
            throw new InvalidArgumentException('Dependency resolution cannot cross workspaces.');
        }

        $target = $catalog[$dependency->versionId] ?? null;
        if ($target === null) {
            throw new InvalidArgumentException("Exact dependency version is unavailable: {$dependency->versionId}");
        }

        if ($target->workspaceId !== $workspaceId) {
            throw new InvalidArgumentException('Resolved dependency belongs to another workspace.');
        }

        $this->assertKindMatches($dependency, $target);

        if (isset($visiting[$target->id])) {
            throw new InvalidArgumentException("Dependency cycle detected at exact version {$target->id}.");
        }

        if (isset($visited[$target->id])) {
            return;
        }

        $visiting[$target->id] = true;

        $children = $target->dependencies;
        usort(
            $children,
            static fn (DependencyReference $left, DependencyReference $right): int => $left->key() <=> $right->key(),
        );

        foreach ($children as $child) {
            $this->visit($workspaceId, $child, $catalog, $visiting, $visited, $resolved);
        }

        unset($visiting[$target->id]);
        $visited[$target->id] = true;
        $resolved[] = $target;
    }

    private function assertKindMatches(DependencyReference $reference, VersionedDefinition $target): void
    {
        $expected = match ($reference->kind) {
            DependencyKind::TemplateVersion => DefinitionKind::Template,
            DependencyKind::ComponentVersion => DefinitionKind::Component,
        };

        if ($target->kind !== $expected) {
            throw new InvalidArgumentException(
                "Dependency {$reference->versionId} kind does not match {$reference->kind->value}.",
            );
        }
    }
}
