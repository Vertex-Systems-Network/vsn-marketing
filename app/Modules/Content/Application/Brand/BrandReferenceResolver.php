<?php

namespace App\Modules\Content\Application\Brand;

use App\Modules\Content\Domain\Brand\BrandReference;
use App\Modules\Content\Domain\Brand\BrandVersion;
use InvalidArgumentException;

final class BrandReferenceResolver
{
    /**
     * @param  list<BrandReference>  $references
     * @param  list<BrandVersion>  $availableVersions
     */
    public function resolve(
        string $workspaceId,
        array $references,
        array $availableVersions,
    ): ResolvedBrandReferences {
        if (trim($workspaceId) === '') {
            throw new InvalidArgumentException('Brand reference resolution workspace id must not be empty.');
        }

        $catalog = [];

        foreach ($availableVersions as $version) {
            if ($version instanceof BrandVersion === false) {
                throw new InvalidArgumentException('Brand version catalog must contain BrandVersion values.');
            }

            if (isset($catalog[$version->id])) {
                throw new InvalidArgumentException("Duplicate brand version catalog id: {$version->id}");
            }

            $catalog[$version->id] = $version;
        }

        $references = array_values($references);

        foreach ($references as $reference) {
            if ($reference instanceof BrandReference === false) {
                throw new InvalidArgumentException('Brand references must contain BrandReference values.');
            }
        }

        usort(
            $references,
            static fn (BrandReference $left, BrandReference $right): int => $left->key() <=> $right->key(),
        );

        $seen = [];
        $resolved = [];

        foreach ($references as $reference) {

            if ($reference->workspaceId !== $workspaceId) {
                throw new InvalidArgumentException('Brand reference resolution cannot cross workspaces.');
            }

            if (isset($seen[$reference->key()])) {
                throw new InvalidArgumentException("Duplicate exact brand reference: {$reference->key()}");
            }

            $seen[$reference->key()] = true;

            $version = $catalog[$reference->brandVersionId] ?? null;

            if ($version === null) {
                throw new InvalidArgumentException("Exact brand version is unavailable: {$reference->brandVersionId}");
            }

            if ($version->workspaceId !== $workspaceId) {
                throw new InvalidArgumentException('Resolved brand version belongs to another workspace.');
            }

            if ($version->brandKitId !== $reference->brandKitId) {
                throw new InvalidArgumentException('Resolved brand version does not belong to the referenced brand kit.');
            }

            $resolved[] = $version;
        }

        return new ResolvedBrandReferences($workspaceId, $resolved);
    }
}
