<?php

namespace App\Modules\Content\Domain\Document;

use InvalidArgumentException;

final readonly class ContentNode
{
    /**
     * @param array<string, mixed> $properties
     * @param list<ContentNode> $children
     */
    public function __construct(
        public string $nodeId,
        public CanonicalNodeType $type,
        public array $properties = [],
        public array $children = [],
    ) {
        if (trim($this->nodeId) === '') {
            throw new InvalidArgumentException('Canonical content node id must not be empty.');
        }

        if ($this->type->acceptsChildren() === false && $this->children !== []) {
            throw new InvalidArgumentException('Canonical leaf nodes cannot contain children.');
        }

        $this->assertPropertyContract();
        self::assertSafeValue($this->properties, 'properties');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->nodeId,
            'type' => $this->type->value,
            'properties' => $this->properties,
            'children' => array_map(
                static fn (self $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }

    private function assertPropertyContract(): void
    {
        [$required, $allowed] = match ($this->type) {
            CanonicalNodeType::Root => [[], []],
            CanonicalNodeType::Section => [[], ['layout', 'style']],
            CanonicalNodeType::Text => [['text'], ['text', 'style', 'accessibility']],
            CanonicalNodeType::Image => [['asset_ref'], ['asset_ref', 'alt', 'style', 'accessibility']],
            CanonicalNodeType::Button => [['label', 'href'], ['label', 'href', 'style', 'accessibility']],
            CanonicalNodeType::ComponentReference => [['component_version_id'], ['component_version_id', 'bindings']],
            CanonicalNodeType::Slot => [['name'], ['name', 'schema']],
        };

        foreach ($required as $key) {
            if (array_key_exists($key, $this->properties) === false) {
                throw new InvalidArgumentException("Canonical {$this->type->value} node is missing required property: {$key}");
            }
        }

        foreach (array_keys($this->properties) as $key) {
            if (is_string($key) === false || in_array($key, $allowed, true) === false) {
                throw new InvalidArgumentException("Unsupported canonical {$this->type->value} node property: ".(string) $key);
            }
        }

        if ($this->type === CanonicalNodeType::Text && is_string($this->properties['text']) === false) {
            throw new InvalidArgumentException('Canonical text node text must be a string.');
        }

        foreach (['asset_ref', 'component_version_id', 'name', 'label', 'href'] as $key) {
            if (array_key_exists($key, $this->properties) === false) {
                continue;
            }

            $value = $this->properties[$key];
            if (is_string($value) === false || trim($value) === '') {
                throw new InvalidArgumentException("Canonical node {$key} must be a non-empty string.");
            }
        }

        if ($this->type === CanonicalNodeType::Button) {
            $href = strtolower(trim((string) $this->properties['href']));
            if (str_starts_with($href, 'javascript:') || str_starts_with($href, 'data:')) {
                throw new InvalidArgumentException('Canonical button href cannot use an executable or data URL scheme.');
            }
        }
    }

    private static function assertSafeValue(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                if (is_string($key) && preg_match('/^(?:raw_html|provider_html|provider_payload|mime_payload|javascript|script|executable)$/i', $key)) {
                    throw new InvalidArgumentException("Provider/render executable property is forbidden in canonical content: {$path}.{$key}");
                }

                self::assertSafeValue($nested, $path.'.'.(string) $key);
            }

            return;
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        throw new InvalidArgumentException("Canonical content property must be JSON-compatible: {$path}");
    }
}
