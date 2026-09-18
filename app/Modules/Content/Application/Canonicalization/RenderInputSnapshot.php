<?php

namespace App\Modules\Content\Application\Canonicalization;

use App\Modules\Content\Domain\Binding\ResolvedBindings;
use InvalidArgumentException;
use JsonException;

final readonly class RenderInputSnapshot
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $dependencyVersionIds
     * @param  list<string>  $assetReferences
     * @param  list<string>  $brandReferences
     */
    public function __construct(
        public string $workspaceId,
        public string $contentVersionId,
        public ?string $templateVersionId,
        public array $dependencyVersionIds,
        public ResolvedBindings $bindings,
        public array $assetReferences = [],
        public array $brandReferences = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        foreach (['workspaceId' => $this->workspaceId, 'contentVersionId' => $this->contentVersionId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Render input snapshot {$field} must not be empty.");
            }
        }

        if ($this->templateVersionId !== null && trim($this->templateVersionId) === '') {
            throw new InvalidArgumentException('Template version id must be null or non-empty.');
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported render input snapshot schema version: {$this->schemaVersion}");
        }

        self::assertStableReferences($this->dependencyVersionIds, 'dependencyVersionIds');
        self::assertStableReferences($this->assetReferences, 'assetReferences');
        self::assertStableReferences($this->brandReferences, 'brandReferences');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $dependencyVersionIds = $this->dependencyVersionIds;
        $assetReferences = $this->assetReferences;
        $brandReferences = $this->brandReferences;

        sort($dependencyVersionIds, SORT_STRING);
        sort($assetReferences, SORT_STRING);
        sort($brandReferences, SORT_STRING);

        return [
            'schema_version' => $this->schemaVersion,
            'workspace_id' => $this->workspaceId,
            'content_version_id' => $this->contentVersionId,
            'template_version_id' => $this->templateVersionId,
            'dependency_version_ids' => $dependencyVersionIds,
            'bindings' => $this->bindings->toArray(),
            'asset_references' => $assetReferences,
            'brand_references' => $brandReferences,
        ];
    }

    /** @throws JsonException */
    public function identity(CanonicalJsonHasher $hasher): string
    {
        return $hasher->hash($this->toArray());
    }

    /** @param  list<string>  $references */
    private static function assertStableReferences(array $references, string $field): void
    {
        $seen = [];

        foreach ($references as $reference) {
            if (trim($reference) === '') {
                throw new InvalidArgumentException("Render input snapshot {$field} contains an empty reference.");
            }

            if (isset($seen[$reference])) {
                throw new InvalidArgumentException("Render input snapshot {$field} contains a duplicate reference.");
            }

            $seen[$reference] = true;
        }
    }
}
