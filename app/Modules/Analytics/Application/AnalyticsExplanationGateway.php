<?php

namespace App\Modules\Analytics\Application;

use App\Modules\AI\Application\AiAgentCatalog;
use App\Modules\AI\Application\AiGateway;
use App\Modules\AI\Domain\AiAgentOutputPolicy;
use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiGatewayOutputValidator;
use App\Modules\AI\Domain\Contracts\AiOfflineAdapter;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\Analytics\Domain\AnalyticsExplanation;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Explicit offline composition only; no live model route is inferred from aggregate availability. */
final readonly class AnalyticsExplanationGateway
{
    public function __construct(private AnalyticsFacts $facts, private array $routes, private array $adapters,
        private AiBudgetLedger $budget, private AiCircuitBreaker $circuit, private AiTelemetryRecorder $telemetry, private string $region, private Clock $clock) {}

    public function explain(TenantContext $actor, string $snapshot, string $trace, int $ceiling): array
    {
        $report = $this->facts->readSnapshot($actor, $snapshot);
        if (new DateTimeImmutable($report['receipt_cutoff_utc']) < $this->clock->now()->modify('-1 day')) {
            throw new \InvalidArgumentException('Explanation snapshot is stale.');
        }
        $policy = new AnalyticsExplanation;
        $definition = (new AiAgentCatalog(base_path()))->resolve('analytics', 'v1');
        $validator = new class($report, $policy, $definition) implements AiGatewayOutputValidator
        {
            public function __construct(private readonly array $report, private readonly AnalyticsExplanation $policy, private readonly array $definition) {}

            public function validate(array $result, array $request, TenantContext $scope): array
            {
                $validated = (new AiAgentOutputPolicy)->validate($result, $this->definition, $scope, [$this->report['id']]);
                $output = $validated['output'];
                $facts = $inferences = [];
                $allowed = [];
                foreach ($this->policy->metrics($this->report) as $metric => $value) {
                    $allowed['Measured '.$metric.': '.$value.'.'] = ['metric' => $metric, 'value' => $value];
                }
                foreach ($output['recommendations'] as $text) {
                    if (isset($allowed[$text])) {
                        $facts[] = $allowed[$text];
                    } elseif (in_array($text, ['Inference: source_coverage_unknown.', 'Inference: observed_change_needs_investigation.', 'Inference: horizon_incomplete.'], true)) {
                        $inferences[] = substr($text, 11, -1);
                    } else {
                        throw new \InvalidArgumentException('AI explanation has ungrounded prose/number/command.');
                    }
                }

                return $this->policy->validate(['snapshot_id' => $this->report['id'], 'fingerprint' => $this->report['fingerprint'],
                    'facts' => $facts, 'inferences' => $inferences], $this->report);
            }
        };
        $routes = array_values(array_filter($this->routes, fn (array $r): bool => ($r['evidence_kind'] ?? null) === 'offline_contract'
            && ($this->adapters[$r['adapter_id'] ?? ''] ?? null) instanceof AiOfflineAdapter));
        $gateway = new AiGateway(new AiRoutePolicy, $this->budget, $this->adapters, $this->circuit, $this->telemetry, $validator);

        return $gateway->generate($routes, ['trace_id' => $trace, 'agent_id' => 'analytics', 'risk_tier' => 'R0',
            'data_region' => $this->region, 'data_classification' => 'approved_non_personal',
            'required_capabilities' => ['structured_output', 'usage_telemetry'], 'required_tool_ids' => ['read_analytics'],
            'max_cost_minor' => $ceiling, 'output_schema_id' => $definition['schema_id'],
            'prompt_id' => $definition['prompt_id'], 'prompt_version' => $definition['version'],
            'prompt_sha256' => $definition['prompt_sha256'], 'instructions' => $definition['instructions'],
            'context_manifest_sha256' => hash('sha256', json_encode([$actor->workspaceId, $snapshot, $report['fingerprint']], JSON_THROW_ON_ERROR)),
            'untrusted_context' => ['snapshot_id' => $snapshot, 'fingerprint' => $report['fingerprint'], 'metrics' => $policy->metrics($report)],
            'fallback_policy' => 'compatible_only'], $actor);
    }
}
