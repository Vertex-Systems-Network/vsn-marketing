<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiAgentOutputPolicy;
use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiGatewayOutputValidator;
use App\Modules\AI\Domain\Contracts\AiOfflineAdapter;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

/** Routes, region and adapters are trusted server configuration; no live defaults. */
final class AiAgentProposalGateway
{
    public function __construct(
        private readonly array $routes,
        private readonly array $adapters,
        private readonly AiBudgetLedger $budget,
        private readonly AiCircuitBreaker $circuit,
        private readonly AiTelemetryRecorder $telemetry,
        private readonly string $region,
    ) {}

    public function propose(array $definition, array $context, TenantContext $scope, string $trace, int $ceiling): array
    {
        $validator = new class($definition, $context) implements AiGatewayOutputValidator
        {
            public function __construct(private readonly array $definition, private readonly array $context) {}

            public function validate(array $result, array $request, TenantContext $scope): array
            {
                return (new AiAgentOutputPolicy)->validate($result, $this->definition, $scope, array_column($this->context['manifest']['sources'], 'source_id'));
            }
        };
        // Candidate runtime cannot infer production permission from fixture success.
        $routes = array_values(array_filter($this->routes, fn (array $route): bool => ($route['evidence_kind'] ?? null) === 'offline_contract'
            && ($this->adapters[$route['adapter_id'] ?? ''] ?? null) instanceof AiOfflineAdapter));
        $gateway = new AiGateway(new AiRoutePolicy, $this->budget, $this->adapters, $this->circuit, $this->telemetry, $validator);
        $classification = 'public';
        foreach ($context['manifest']['sources'] as $source) {
            if ($source['source_kind'] !== 'brand_guideline') {
                $classification = 'approved_non_personal';
            }
        }

        return $gateway->generate($routes, [
            'trace_id' => $trace, 'agent_id' => $definition['agent_id'], 'data_region' => $this->region,
            'data_classification' => $classification, 'required_capabilities' => ['structured_output', 'usage_telemetry'],
            'risk_tier' => $definition['risk_tier'], 'max_cost_minor' => $ceiling,
            'required_tool_ids' => $definition['tools'], 'output_schema_id' => $definition['schema_id'],
            'prompt_id' => $definition['prompt_id'], 'prompt_version' => $definition['version'],
            'prompt_sha256' => $definition['prompt_sha256'], 'instructions' => $definition['instructions'],
            'context_manifest_sha256' => $context['manifest_sha256'], 'untrusted_context' => $context['untrusted_context'],
            'fallback_policy' => 'compatible_only',
        ], $scope);
    }
}
