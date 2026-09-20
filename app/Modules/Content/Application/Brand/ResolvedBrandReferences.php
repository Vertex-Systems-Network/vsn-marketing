<?php

namespace App\Modules\Content\Application\Brand;

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Domain\Brand\BrandVersion;
use InvalidArgumentException;
use JsonException;

final readonly class ResolvedBrandReferences
{
    /** @param list<BrandVersion> $versions */
    public function __construct(
        public string $workspaceId,
        public array $versions,
    ) {
        if (trim($this->workspaceId) === '') {
            throw new InvalidArgumentException('Resolved brand workspace id must not be empty.');
        }

        $brandKits = [];

        foreach ($this->versions as $version) {
            if ($version instanceof BrandVersion === false) {
                throw new InvalidArgumentException('Resolved brand references must contain BrandVersion values.');
            }

            if ($version->workspaceId !== $this->workspaceId) {
                throw new InvalidArgumentException('Resolved brand version belongs to another workspace.');
            }

            if (isset($brandKits[$version->brandKitId])) {
                throw new InvalidArgumentException("Only one exact brand version may be resolved per brand kit: {$version->brandKitId}");
            }

            $brandKits[$version->brandKitId] = true;
        }
    }

    /** @return list<string> */
    public function versionIds(): array
    {
        $versions = $this->versions;
        usort(
            $versions,
            static fn (BrandVersion $left, BrandVersion $right): int => $left->brandKitId <=> $right->brandKitId,
        );

        return array_map(
            static fn (BrandVersion $version): string => $version->id,
            $versions,
        );
    }

    /** @return array<string, array<string, string|int|float|bool>> */
    public function tokenMap(): array
    {
        $versions = $this->versions;
        usort(
            $versions,
            static fn (BrandVersion $left, BrandVersion $right): int => $left->brandKitId <=> $right->brandKitId,
        );

        $resolved = [];

        foreach ($versions as $version) {
            $tokens = [];

            foreach ($version->toArray()['style_tokens'] as $token) {
                $tokens[$token['key']] = $token['value'];
            }

            ksort($tokens, SORT_STRING);
            $resolved[$version->brandKitId] = $tokens;
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $versions = $this->versions;
        usort(
            $versions,
            static fn (BrandVersion $left, BrandVersion $right): int => $left->brandKitId <=> $right->brandKitId,
        );

        return [
            'workspace_id' => $this->workspaceId,
            'brand_versions' => array_map(
                static fn (BrandVersion $version): array => $version->toArray(),
                $versions,
            ),
        ];
    }

    /** @throws JsonException */
    public function identity(CanonicalJsonHasher $hasher): string
    {
        return $hasher->hash($this->toArray());
    }
}
