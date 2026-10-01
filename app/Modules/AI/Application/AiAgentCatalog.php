<?php

namespace App\Modules\AI\Application;

use InvalidArgumentException;

/** Immutable application files and registries; never populated from model/request data. */
final class AiAgentCatalog
{
    public function __construct(private readonly string $root) {}

    public function resolve(string $agentId, string $version): array
    {
        $agents = $this->read('.ai/ai/AGENT-REGISTRY.yaml')['agents'];
        $prompts = $this->read('.ai/ai/PROMPT-REGISTRY.yaml')['prompts'];
        $evals = $this->read('.ai/ai/EVAL-REGISTRY.yaml')['suites'];
        $agent = $this->one($agents, 'id', $agentId);
        $prompt = $this->one($prompts, 'agent_id', $agentId);
        $entry = $this->one($prompt['versions'] ?? [], 'version', $version);
        $definition = $this->pinned($entry, 'prompts');
        $suite = $this->one($evals, 'id', $prompt['required_eval_suite']);
        $datasetEntry = $this->one($suite['versions'] ?? [], 'version', $version);
        $dataset = $this->pinned($datasetEntry, 'evals');
        if (($definition['agent_id'] ?? null) !== $agentId || ($definition['version'] ?? null) !== $version
            || ($definition['prompt_id'] ?? null) !== $prompt['id']
            || ($definition['schema_id'] ?? null) !== $agent['output_contract']
            || ! is_string($definition['instructions'] ?? null) || $definition['instructions'] === ''
            || ($dataset['id'] ?? null) !== $suite['id'] || ($dataset['version'] ?? null) !== $version) {
            throw new InvalidArgumentException('Agent prompt/evaluation binding rejected.');
        }

        return $definition + ['tools' => $agent['tools'], 'memory_scopes' => $agent['memory_scopes'],
            'risk_tier' => $agent['max_autonomy'], 'prompt_sha256' => $entry['sha256'],
            'eval_id' => $suite['id'], 'dataset_sha256' => $datasetEntry['sha256'], 'dataset' => $dataset];
    }

    private function one(array $rows, string $key, string $value): array
    {
        $matches = array_values(array_filter($rows, static fn (array $row): bool => ($row[$key] ?? null) === $value));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Unregistered or ambiguous agent version.');
        }

        return $matches[0];
    }

    private function pinned(array $entry, string $kind): array
    {
        $path = $entry['path'] ?? '';
        if (! is_string($path) || ! preg_match('#^resources/ai/'.$kind.'/[a-z_]+\.v[1-9][0-9]*\.json$#D', $path)
            || ! is_string($entry['sha256'] ?? null) || ! is_file($this->root.'/'.$path)
            || hash_file('sha256', $this->root.'/'.$path) !== $entry['sha256']) {
            throw new InvalidArgumentException('Immutable agent artifact changed or missing.');
        }

        return $this->read($path);
    }

    private function read(string $path): array
    {
        $bytes = file_get_contents($this->root.'/'.$path);
        if ($bytes === false || strlen($bytes) > 65536) {
            throw new InvalidArgumentException('Agent artifact is unavailable or over budget.');
        }

        return json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    }
}
