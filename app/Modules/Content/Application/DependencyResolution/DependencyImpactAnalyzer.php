<?php

namespace App\Modules\Content\Application\DependencyResolution;

use App\Modules\Templates\Domain\VersionedDefinition;
use InvalidArgumentException;

final class DependencyImpactAnalyzer
{
    /**
     * @param  list<VersionedDefinition>  $versions
     * @return list<string>
     */
    public function impactedDependents(string $workspaceId, string $versionId, array $versions): array
    {
        if (trim($workspaceId) === '' || trim($versionId) === '') {
            throw new InvalidArgumentException('Impact analysis requires workspace and version identifiers.');
        }

        $reverse = [];

        foreach ($versions as $version) {
            if ($version->workspaceId !== $workspaceId) {
                continue;
            }

            foreach ($version->dependencies as $dependency) {
                if ($dependency->workspaceId !== $workspaceId) {
                    throw new InvalidArgumentException('Impact analysis encountered a cross-workspace dependency.');
                }

                $reverse[$dependency->versionId][] = $version->id;
            }
        }

        foreach ($reverse as &$dependents) {
            sort($dependents, SORT_STRING);
        }
        unset($dependents);

        $queue = [$versionId];
        $seen = [];
        $impacted = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($reverse[$current] ?? [] as $dependentId) {
                if (isset($seen[$dependentId])) {
                    continue;
                }

                $seen[$dependentId] = true;
                $impacted[] = $dependentId;
                $queue[] = $dependentId;
            }
        }

        sort($impacted, SORT_STRING);

        return $impacted;
    }
}
