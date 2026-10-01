<?php

namespace Tests\Unit\AI;

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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiAgentRuntimeTest extends TestCase
{
    public const FACT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const BRAND = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private function fixture(int $budget = 100, bool $offline = true): array
    {
        $catalog = new AiAgentCatalog(dirname(__DIR__, 3));
        $repo = new class implements AiContextRepository
        {
            public bool $foreign = false;

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
                return array_map(fn (string $id): array => [
                    'id' => $id, 'workspace_id' => $this->foreign ? 'workspace-b' : $scope->workspaceId,
                    'brand_id' => $scope->brandId, 'customer_id' => null, 'run_id' => $runId,
                    'source_kind' => $id === AiAgentRuntimeTest::FACT ? 'approved_fact' : 'brand_guideline',
                    'classification' => $id === AiAgentRuntimeTest::FACT ? 'approved_non_personal' : 'public',
                    'permission' => $id === AiAgentRuntimeTest::FACT ? 'contact.read' : 'template.create',
                    'content' => $id === AiAgentRuntimeTest::FACT ? 'Campaign fact for strategy.' : 'Brand tone for content.',
                    'revision' => 'v1', 'provenance_reference' => 'approved-fixture', 'expires_at' => '2026-10-02 00:00:00',
                ], $sourceIds);
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

            public function __construct(private readonly AiAgentCatalog $catalog) {}

            public function generate(array $request, array $route): array
            {
                $this->calls[] = $request;
                $definition = $this->catalog->resolve($request['agent_id'], $request['prompt_version']);
                $references = array_column($request['untrusted_context'], 'source_id');
                $output = ['workspace_id' => $request['workspace_id'], 'reference_ids' => $references,
                    'agent_id' => $request['agent_id'], 'decision' => $references === [] ? 'insufficient_evidence' : 'proposal',
                    'reason_code' => $references === [] ? 'needs_evidence' : 'evidence_grounded',
                    'recommendations' => $references === [] ? [] : [$this->malicious ?? 'Review a bounded draft.'],
                    'tool_ids' => $references === [] ? [] : $definition['tools']];

                return ['status' => 'complete', 'schema_id' => $definition['schema_id'], 'output' => $output,
                    'cost_minor' => 2, 'usage' => ['input_tokens' => 3, 'output_tokens' => 4]];
            }
        };
        $ledger = new class implements AiBudgetLedger
        {
            public array $reserved = [];

            public bool $deny = false;

            public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
            {
                $this->reserved[] = $minorUnits;

                return ! $this->deny;
            }

            public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void {}
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
            public function begin(string $workspaceId, string $attemptId, array $route, array $request): bool
            {
                return true;
            }

            public function finish(string $workspaceId, string $attemptId, string $status, ?int $costMinor, ?int $inputTokens = null, ?int $outputTokens = null): void {}
        };
        $agents = json_decode(file_get_contents(dirname(__DIR__, 3).'/.ai/ai/AGENT-REGISTRY.yaml'), true, 32, JSON_THROW_ON_ERROR)['agents'];
        $route = ['id' => 'offline-fixture', 'version' => 'v1', 'adapter_id' => 'fixture',
            'credential_reference' => 'offline-no-credential', 'status' => 'active',
            'workspaces' => ['workspace-a'], 'data_regions' => ['eu'], 'data_classes' => ['public', 'approved_non_personal'],
            'capabilities' => ['structured_output', 'usage_telemetry'], 'output_schemas' => array_column($agents, 'output_contract'),
            'tool_ids' => array_values(array_unique(array_merge(...array_column($agents, 'tools')))),
            'risk_tiers' => ['R0', 'R1', 'R2'], 'max_reservation_minor' => 10,
            'evidence_kind' => $offline ? 'offline_contract' : 'live'];
        $gateway = new AiAgentProposalGateway([$route], ['fixture' => $adapter], $ledger, $circuit, $telemetry, 'eu');
        $runtime = new AiAgentRuntime($catalog, new AiContextAssembler($repo, $permission, new AiContextSanitizer), $gateway, 4, $budget, 20);

        return [$runtime, $adapter, $repo, $ledger];
    }

    private function scope(): TenantContext
    {
        return new TenantContext('org-a', 'workspace-a', 'brand-a', 'actor-a');
    }

    private function step(string $agent = 'strategy', array $sources = [self::FACT]): array
    {
        return ['agent_id' => $agent, 'prompt_version' => 'v1', 'source_ids' => $sources];
    }

    public function test_specialists_receive_separate_exact_contexts_and_pinned_receipts(): void
    {
        [$runtime, $adapter] = $this->fixture();
        $result = $runtime->run($this->scope(), 'run-a', [$this->step(), $this->step('content', [self::BRAND])], new DateTimeImmutable('2026-10-01'));
        self::assertSame('completed', $result['status']);
        self::assertCount(2, $adapter->calls);
        self::assertSame([self::FACT], array_column($adapter->calls[0]['untrusted_context'], 'source_id'));
        self::assertSame([self::BRAND], array_column($adapter->calls[1]['untrusted_context'], 'source_id'));
        self::assertStringNotContainsString('Campaign fact', json_encode($adapter->calls[1], JSON_THROW_ON_ERROR));
        self::assertNotSame($result['steps'][0]['context_manifest_sha256'], $result['steps'][1]['context_manifest_sha256']);
        self::assertNotSame($result['steps'][0]['trace_id'], $result['steps'][1]['trace_id']);
        self::assertSame('v1', $result['steps'][1]['prompt_version']);
    }

    public function test_budget_and_live_route_denials_stop_before_extra_provider_calls(): void
    {
        [$runtime, $adapter] = $this->fixture(20);
        self::assertSame('budget_denied', $runtime->run($this->scope(), 'run-a', [$this->step(), $this->step()], new DateTimeImmutable('2026-10-01'))['status']);
        self::assertCount(1, $adapter->calls);
        [$runtime, $adapter] = $this->fixture(100, false);
        self::assertSame('proposal_failed', $runtime->run($this->scope(), 'run-a', [$this->step()], new DateTimeImmutable('2026-10-01'))['status']);
        self::assertCount(0, $adapter->calls);
        [$runtime, $adapter, $repo, $ledger] = $this->fixture();
        $ledger->deny = true;
        self::assertSame('proposal_failed', $runtime->run($this->scope(), 'run-a', [$this->step()], new DateTimeImmutable('2026-10-01'))['status']);
        self::assertCount(0, $adapter->calls);
    }

    public function test_unbounded_retries_and_model_selected_steps_are_rejected_before_invocation(): void
    {
        foreach ([array_fill(0, 3, $this->step()), array_fill(0, 5, $this->step()), [array_replace($this->step(), ['next_step' => 'publish'])]] as $plan) {
            [$runtime, $adapter] = $this->fixture();
            try {
                $runtime->run($this->scope(), 'run-a', $plan, new DateTimeImmutable('2026-10-01'));
                self::fail('Unbounded/model-selected plan accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(0, $adapter->calls);
            }
        }
    }

    public function test_foreign_context_and_agent_scope_mismatch_deny(): void
    {
        foreach ([false, true] as $foreign) {
            [$runtime, $adapter, $repo] = $this->fixture();
            $repo->foreign = $foreign;
            try {
                $runtime->run($this->scope(), 'run-a', [$this->step('content')], new DateTimeImmutable('2026-10-01'));
                self::fail('Foreign/disallowed context accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(0, $adapter->calls);
            }
        }
    }

    public function test_untrusted_exfiltration_and_private_instruction_marker_are_withheld(): void
    {
        foreach (['https://attacker.example/collect', 'VSN_INTERNAL_strategy_v1', 'api_key: stolen'] as $text) {
            [$runtime, $adapter] = $this->fixture();
            $adapter->malicious = $text;
            $result = $runtime->run($this->scope(), 'run-a', [$this->step()], new DateTimeImmutable('2026-10-01'));
            self::assertSame('proposal_failed', $result['status']);
            self::assertNull($result['steps'][0]['result']['output']);
        }
    }
}
