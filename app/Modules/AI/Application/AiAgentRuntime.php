<?php

namespace App\Modules\AI\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/** A finite server-selected proposal plan. Output cannot create steps or inherit memory. */
final class AiAgentRuntime
{
    public function __construct(
        private readonly AiAgentCatalog $catalog,
        private readonly AiContextAssembler $contexts,
        private readonly AiAgentProposalGateway $gateway,
        private readonly int $maxSteps,
        private readonly int $maxRunCost,
        private readonly int $stepCeiling,
    ) {
        if ($maxSteps < 1 || $maxSteps > 8 || $maxRunCost < 1 || $stepCeiling < 1 || $stepCeiling > $maxRunCost) {
            throw new InvalidArgumentException('Agent run policy bounds rejected.');
        }
    }

    public function run(TenantContext $scope, string $runId, array $plan, DateTimeImmutable $at): array
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $runId) || ! array_is_list($plan) || $plan === [] || count($plan) > $this->maxSteps) {
            throw new InvalidArgumentException('Agent plan/run bound rejected.');
        }
        $definitions = [];
        $counts = [];
        foreach ($plan as $step) {
            if (! is_array($step) || array_diff(array_keys($step), ['agent_id', 'prompt_version', 'source_ids']) !== []
                || ! is_string($step['agent_id'] ?? null) || ! is_string($step['prompt_version'] ?? null)
                || ! is_array($step['source_ids'] ?? null) || ! array_is_list($step['source_ids'])) {
                throw new InvalidArgumentException('Agent plan step rejected.');
            }
            $counts[$step['agent_id']] = ($counts[$step['agent_id']] ?? 0) + 1;
            if ($counts[$step['agent_id']] > 2) {
                throw new InvalidArgumentException('Agent retry/loop bound exceeded.');
            }
            $definitions[] = $this->catalog->resolve($step['agent_id'], $step['prompt_version']);
        }
        $remaining = $this->maxRunCost;
        $receipts = [];
        foreach ($plan as $index => $step) {
            if ($remaining < $this->stepCeiling) {
                return ['status' => 'budget_denied', 'steps' => $receipts];
            }
            $definition = $definitions[$index];
            $context = $this->contexts->assemble($scope, null, $runId, $step['source_ids'], $at);
            foreach ($context['manifest']['sources'] as $source) {
                $memory = match ($source['source_kind']) {
                    'brand_guideline' => 'brand', 'run_note' => 'run', default => 'workspace',
                };
                if (! in_array($memory, $definition['memory_scopes'], true)) {
                    throw new InvalidArgumentException('Agent memory scope rejected.');
                }
            }
            // Charge the ceiling even on failure/unknown usage; no retry releases budget.
            $remaining -= $this->stepCeiling;
            $trace = hash('sha256', json_encode([$scope->toArray(), $runId, $index, $definition['prompt_sha256']], JSON_THROW_ON_ERROR));
            $started = hrtime(true);
            $result = $this->gateway->propose($definition, $context, $scope, $trace, $this->stepCeiling);
            $receipts[] = ['trace_id' => $trace, 'agent_id' => $definition['agent_id'],
                'prompt_id' => $definition['prompt_id'], 'prompt_version' => $definition['version'],
                'prompt_sha256' => $definition['prompt_sha256'], 'context_manifest_sha256' => $context['manifest_sha256'],
                'latency_ms' => (hrtime(true) - $started) / 1000000, 'result' => $result];
            if (($result['status'] ?? null) !== 'complete' || ($result['validation_status'] ?? null) !== 'validated') {
                return ['status' => 'proposal_failed', 'steps' => $receipts];
            }
        }

        return ['status' => 'completed', 'steps' => $receipts];
    }
}
