<?php

namespace App\Modules\Content\Domain\Document;

use InvalidArgumentException;

final readonly class ContentTree
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public ContentNode $root,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported canonical content tree schema version: {$this->schemaVersion}");
        }

        if ($this->root->type !== CanonicalNodeType::Root) {
            throw new InvalidArgumentException('Canonical content tree root node must use the root node type.');
        }

        $seen = [];
        $this->assertUniqueNodeIds($this->root, $seen, 0);
    }

    /** @return array{schema_version: int, root: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'root' => $this->root->toArray(),
        ];
    }

    /** @param array<string, true> $seen */
    private function assertUniqueNodeIds(ContentNode $node, array &$seen, int $depth): void
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Canonical content tree depth cannot exceed 64 nodes.');
        }

        if (isset($seen[$node->nodeId])) {
            throw new InvalidArgumentException("Duplicate canonical content node id: {$node->nodeId}");
        }

        $seen[$node->nodeId] = true;

        foreach ($node->children as $child) {
            $this->assertUniqueNodeIds($child, $seen, $depth + 1);
        }
    }
}
