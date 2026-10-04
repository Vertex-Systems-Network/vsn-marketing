<?php

namespace App\Modules\AI\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

/** Computed policy evidence. No public constructor accepting a model's pass flag. */
final readonly class AiEvaluationReport
{
    private function __construct(
        public string $promptHash,
        public string $datasetHash,
        public string $suiteId,
        public bool $passed,
        public array $outcomes,
    ) {}

    public static function evaluate(array $definition, array $samples): self
    {
        $cases = $definition['dataset']['cases'];
        $ids = array_column($cases, 'id');
        $complete = count($ids) >= 4 && count($ids) === count(array_unique($ids))
            && count(array_filter($cases, fn (array $case): bool => in_array($case['expected'] ?? null, ['validated', 'rejected'], true))) === count($cases)
            && array_diff($ids, array_keys($samples)) === [] && array_diff(array_keys($samples), $ids) === [];
        $outcomes = [];
        foreach ($cases as $case) {
            $accepted = false;
            $matches = false;
            try {
                $scope = new TenantContext('offline-fixture', $case['workspace_id'], null, 'offline-evaluator');
                $validated = (new AiAgentOutputPolicy)->validate($samples[$case['id']] ?? [], $definition, $scope, $case['source_ids']);
                $accepted = true;
                $matches = true;
                foreach (['agent_id', 'decision', 'reason_code', 'reference_ids', 'tool_ids'] as $field) {
                    $matches = $matches && $validated['output'][$field] === $case['output'][$field];
                }
            } catch (\Throwable) {
                $accepted = false;
            }
            $outcomes[$case['id']] = array_key_exists($case['id'], $samples)
                && ($case['expected'] === 'validated' ? ($accepted && $matches) : ! $accepted);
        }

        return new self($definition['prompt_sha256'], $definition['dataset_sha256'], $definition['eval_id'],
            $complete && ! in_array(false, $outcomes, true), $outcomes);
    }

    public function toArray(): array
    {
        return ['evidence_kind' => 'offline_contract', 'engine_id' => 'agent-policy.v1',
            'prompt_sha256' => $this->promptHash, 'dataset_sha256' => $this->datasetHash,
            'suite_id' => $this->suiteId, 'passed' => $this->passed, 'outcomes' => $this->outcomes];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
