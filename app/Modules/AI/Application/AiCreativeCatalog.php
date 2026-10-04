<?php

namespace App\Modules\AI\Application;

use InvalidArgumentException;

final class AiCreativeCatalog
{
    public function __construct(private readonly string $root) {}

    public function resolve(string $capability, string $version): array
    {
        $registry = json_decode(file_get_contents($this->root.'/.ai/ai/CREATIVE-REGISTRY.yaml'), true, 32, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($registry['capabilities'], static fn (array $row): bool => $row['id'] === $capability));
        if (count($matches) !== 1 || $matches[0]['status'] !== 'offline_candidate') {
            throw new InvalidArgumentException('Creative capability unavailable.');
        }
        $versions = array_values(array_filter($matches[0]['versions'], static fn (array $row): bool => $row['version'] === $version));
        $entry = count($versions) === 1 ? $versions[0] : [];
        $path = $entry['path'] ?? '';
        if (! preg_match('#^resources/ai/creative/creative_(text|image)\.v[1-9][0-9]*\.json$#D', $path)
            || ! is_file($this->root.'/'.$path) || hash_file('sha256', $this->root.'/'.$path) !== ($entry['sha256'] ?? null)) {
            throw new InvalidArgumentException('Creative policy version changed or unavailable.');
        }
        $definition = json_decode(file_get_contents($this->root.'/'.$path), true, 32, JSON_THROW_ON_ERROR);
        if ($definition['id'] !== $capability || $definition['version'] !== $version
            || $definition['evidence_kind'] !== 'offline_contract' || $definition['rights_required'] !== true
            || $definition['provenance_required'] !== true || $definition['publication_allowed'] !== false
            || $definition['review_required'] !== ['rights', 'brand', 'safety']
            || ! is_int($definition['max_media_bytes']) || $definition['max_media_bytes'] < 0 || $definition['max_media_bytes'] > 5242880
            || ! is_int($definition['max_text_bytes']) || $definition['max_text_bytes'] < 1 || $definition['max_text_bytes'] > 4096
            || ! is_int($definition['max_dimension']) || $definition['max_dimension'] < 1 || $definition['max_dimension'] > 4096
            || ! is_string($definition['disclosure']) || $definition['disclosure'] === '') {
            throw new InvalidArgumentException('Creative policy boundary rejected.');
        }

        return $definition + ['policy_sha256' => $entry['sha256']];
    }
}
