<?php

namespace App\Modules\Content\Application\DependencyResolution;

use App\Modules\Templates\Domain\VersionedDefinition;

final readonly class ResolvedDependencySet
{
    /** @param  list<VersionedDefinition>  $versions */
    public function __construct(public array $versions) {}

    /** @return list<string> */
    public function versionIds(): array
    {
        return array_map(
            static fn (VersionedDefinition $version): string => $version->id,
            $this->versions,
        );
    }
}
