<?php

namespace Tests\Support\AI;

use App\Modules\AI\Application\AiAgentCatalog;
use App\Modules\AI\Application\AiAgentProposalGateway;
use App\Modules\AI\Application\AiAgentRuntime;
use App\Modules\AI\Application\AiContextAssembler;
use App\Modules\AI\Domain\AiContextSanitizer;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiContextRepository;
use App\Modules\AI\Domain\Contracts\AiOfflineAdapter;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

/** Synthetic ports; exercises the actual context, runtime and gateway. Never installed in production. */
final class AiRuntimeHarness
{
    public const FACT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public const BRAND = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public static function fixture(int $budget = 100, bool $offline = true, array $routePatch = [], array $fallbackPatch = []): array
    {
        $catalog = new AiAgentCatalog(dirname(__DIR__, 3));
        $repo = new class implements AiContextRepository
        {
            public bool $foreign = false;

            public array $overrides = [];

            public function put(TenantContext $scope, ?string $customerId, ?string $runId, array $source, DateTimeImmutable $at): string
            {
                throw new \LogicException('Read-only fixture.');
            }

            public function delete(TenantContext $scope, ?string $customerId, ?string $runId, string $sourceId): bool
            {
                throw new \LogicException('Read-only fixture.');
            }

            public function fetch(TenantContext $scope, ?string $customerId, ?string $runId, array $sourceIds, DateTimeImmutable $at): array
            {
                return array_map(fn (string $id): array => array_replace([
                    'id' => $id, 'workspace_id' => $this->foreign ? 'workspace-b' : $scope->workspaceId,
                    'brand_id' => $scope->brandId, 'customer_id' => null, 'run_id' => $runId,
                    'source_kind' => $id === AiRuntimeHarness::FACT ? 'approved_fact' : 'brand_guideline',
                    'classification' => $id === AiRuntimeHarness::FACT ? 'approved_non_personal' : 'public',
                    'permission' => $id === AiRuntimeHarness::FACT ? 'contact.read' : 'template.create',
                    'content' => $id === AiRuntimeHarness::FACT ? 'Campaign fact for strategy.' : 'Brand tone for content.',
                    'revision' => 'v1', 'provenance_reference' => 'approved-fixture', 'expires_at' => '2026-10-02 00:00:00',
                ], $this->overrides), $sourceIds);
            }
        };
        $permission = new class implements AiContextPermission
        {
            public function allows(TenantContext $scope, string $permission): bool
            {
                return true;
            }
        };
        $adapter = new class($catalog) implements AiOfflineAdapter
        {
            public array $calls = [];

            public ?string $malicious = null;

            public array $outputPatch = [];

            public bool $throw = false;

            public function __construct(private readonly AiAgentCatalog $catalog) {}

            public function generate(array $request, array $route): array
            {
                $this->calls[] = $request;
                if ($this->throw) {
                    throw new \RuntimeException('Synthetic provider outage.');
                }
                $definition = $this->catalog->resolve($request['agent_id'], $request['prompt_version']);
                $references = array_column($request['untrusted_context'], 'source_id');
                $output = ['workspace_id' => $request['workspace_id'], 'reference_ids' => $references,
                    'agent_id' => $request['agent_id'], 'decision' => $references === [] ? 'insufficient_evidence' : 'proposal',
                    'reason_code' => $references === [] ? 'needs_evidence' : 'evidence_grounded',
                    'recommendations' => $references === [] ? [] : [$this->malicious ?? 'Review a bounded draft.'],
                    'tool_ids' => $references === [] ? [] : $definition['tools']];

                return ['status' => 'complete', 'schema_id' => $definition['schema_id'], 'output' => array_replace($output, $this->outputPatch),
                    'cost_minor' => 2, 'usage' => ['input_tokens' => 3, 'output_tokens' => 4]];
            }
        };
        $ledger = new class implements AiBudgetLedger
        {
            public array $reserved = [];

            public bool $deny = false;

            public array $settled = [];

            public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
            {
                $this->reserved[] = $minorUnits;

                return ! $this->deny;
            }

            public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void
            {
                $this->settled[] = $actualMinorUnits;
            }
        };
        $circuit = new class implements AiCircuitBreaker
        {
            public function allows(string $workspaceId, string $routeId): bool
            {
                return true;
            }

            public function succeeded(string $workspaceId, string $routeId): void {}

            public function failed(string $workspaceId, string $routeId): void {}
        };
        $telemetry = new class implements AiTelemetryRecorder
        {
            public array $statuses = [];

            public function begin(string $workspaceId, string $attemptId, array $route, array $request): bool
            {
                return true;
            }

            public function finish(string $workspaceId, string $attemptId, string $status, ?int $costMinor, ?int $inputTokens = null, ?int $outputTokens = null): void
            {
                $this->statuses[] = $status;
            }
        };
        $agents = json_decode(file_get_contents(dirname(__DIR__, 3).'/.ai/ai/AGENT-REGISTRY.yaml'), true, 32, JSON_THROW_ON_ERROR)['agents'];
        $route = ['id' => 'offline-fixture', 'version' => 'v1', 'adapter_id' => 'fixture',
            'credential_reference' => 'offline-no-credential', 'status' => 'active',
            'workspaces' => ['workspace-a'], 'data_regions' => ['eu'], 'data_classes' => ['public', 'approved_non_personal'],
            'capabilities' => ['structured_output', 'usage_telemetry'], 'output_schemas' => array_column($agents, 'output_contract'),
            'tool_ids' => array_values(array_unique(array_merge(...array_column($agents, 'tools')))),
            'risk_tiers' => ['R0', 'R1', 'R2'], 'max_reservation_minor' => 10,
            'evidence_kind' => $offline ? 'offline_contract' : 'live'];
        $routes = [array_replace($route, $routePatch)];
        if ($fallbackPatch !== []) {
            $routes[] = array_replace($route, ['id' => 'offline-fallback'], $fallbackPatch);
        }
        $gateway = new AiAgentProposalGateway($routes, ['fixture' => $adapter], $ledger, $circuit, $telemetry, 'eu');
        $runtime = new AiAgentRuntime($catalog, new AiContextAssembler($repo, $permission, new AiContextSanitizer), $gateway, 4, $budget, 20);

        return [$runtime, $adapter, $repo, $ledger, $telemetry];
    }

    public static function scope(): TenantContext
    {
        return new TenantContext('org-a', 'workspace-a', 'brand-a', 'actor-a');
    }

    public static function step(string $agent = 'strategy', array $sources = [self::FACT]): array
    {
        return ['agent_id' => $agent, 'prompt_version' => 'v1', 'source_ids' => $sources];
    }
}
