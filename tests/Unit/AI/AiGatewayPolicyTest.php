<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\AiGateway;
use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiAdapter;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\AI\Infrastructure\DenyingAiBudgetLedger;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;

final class AiGatewayPolicyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'trace_id' => 'trace-1', 'workspace_id' => 'workspace-a', 'data_region' => 'eu',
            'data_classification' => 'public', 'required_capabilities' => ['structured_output'],
            'risk_tier' => 'R0', 'max_cost_minor' => 100,
            'prompt_id' => 'draft', 'prompt_version' => 'v1', 'context_manifest_sha256' => str_repeat('a', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function route(): array
    {
        return [
            'id' => 'candidate-1', 'version' => 'v1', 'adapter_id' => 'fake',
            'credential_reference' => 'secret-ref', 'status' => 'active',
            'workspaces' => ['workspace-a'], 'data_regions' => ['eu'],
            'data_classes' => ['public'], 'capabilities' => ['structured_output'],
            'risk_tiers' => ['R0'], 'max_reservation_minor' => 40,
        ];
    }

    private function scope(string $workspace = 'workspace-a'): TenantContext
    {
        return new TenantContext('org-a', $workspace, null, 'actor-a');
    }

    private function circuit(): AiCircuitBreaker
    {
        return new class implements AiCircuitBreaker
        {
            public function allows(string $workspaceId, string $routeId): bool
            {
                return true;
            }

            public function succeeded(string $workspaceId, string $routeId): void {}

            public function failed(string $workspaceId, string $routeId): void {}
        };
    }

    private function telemetry(): AiTelemetryRecorder
    {
        return new class implements AiTelemetryRecorder
        {
            public function begin(string $workspaceId, string $attemptId, array $route, array $request): bool
            {
                return true;
            }

            public function finish(string $workspaceId, string $attemptId, string $status, ?int $costMinor): void {}
        };
    }

    public function test_route_policy_denies_foreign_workspace_region_risk_and_missing_capability(): void
    {
        $policy = new AiRoutePolicy;
        foreach ([
            ['workspace_id' => 'workspace-b'], ['data_region' => 'us'], ['risk_tier' => 'R3'],
            ['required_capabilities' => ['tool_calling']], ['max_cost_minor' => 39],
        ] as $override) {
            self::assertSame([], $policy->eligible([$this->route()], array_replace($this->request(), $override)));
        }

        self::assertCount(1, $policy->eligible([$this->route()], $this->request()));
    }

    public function test_default_budget_binding_denies_before_invoking_adapter(): void
    {
        $adapter = new class implements AiAdapter
        {
            public bool $called = false;

            public function generate(array $request, array $route): array
            {
                $this->called = true;

                return ['status' => 'complete', 'cost_minor' => 1, 'output' => 'unexpected'];
            }
        };
        $gateway = new AiGateway(new AiRoutePolicy, new DenyingAiBudgetLedger, ['fake' => $adapter], $this->circuit(), $this->telemetry());

        self::assertSame(['status' => 'budget_denied', 'trace_id' => 'trace-1'], $gateway->generate([$this->route()], $this->request(), $this->scope()));
        self::assertFalse($adapter->called);
        self::assertSame(['status' => 'route_unavailable', 'trace_id' => 'trace-1'], $gateway->generate([$this->route()], $this->request(), $this->scope('workspace-b')));
    }

    public function test_invalid_usage_does_not_settle_or_expose_output(): void
    {
        $ledger = new class implements AiBudgetLedger
        {
            public int $settles = 0;

            public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
            {
                return true;
            }

            public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void
            {
                $this->settles++;
            }
        };
        $adapter = new class implements AiAdapter
        {
            public function generate(array $request, array $route): array
            {
                return ['status' => 'complete', 'cost_minor' => 41, 'output' => 'untrusted'];
            }
        };
        $gateway = new AiGateway(new AiRoutePolicy, $ledger, ['fake' => $adapter], $this->circuit(), $this->telemetry());

        self::assertSame(['status' => 'provider_failed', 'trace_id' => 'trace-1', 'route_id' => 'candidate-1'], $gateway->generate([$this->route()], $this->request(), $this->scope()));
        self::assertSame(0, $ledger->settles);
    }

    public function test_complete_response_is_normalized_and_accounted(): void
    {
        $ledger = new class implements AiBudgetLedger
        {
            public ?int $actual = null;

            public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
            {
                return $workspaceId === 'workspace-a' && $minorUnits === 40;
            }

            public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void
            {
                $this->actual = $actualMinorUnits;
            }
        };
        $adapter = new class implements AiAdapter
        {
            public function generate(array $request, array $route): array
            {
                return ['status' => 'complete', 'cost_minor' => 12, 'output' => ['proposal' => 'draft']];
            }
        };
        $gateway = new AiGateway(new AiRoutePolicy, $ledger, ['fake' => $adapter], $this->circuit(), $this->telemetry());
        $result = $gateway->generate([$this->route()], $this->request(), $this->scope());

        self::assertSame('complete', $result['status']);
        self::assertSame('candidate-1', $result['route_id']);
        self::assertNull($result['output']);
        self::assertSame(12, $ledger->actual);
    }

    public function test_fallback_preserves_policy_and_total_reservation_budget(): void
    {
        $ledger = new class implements AiBudgetLedger
        {
            /** @var list<int> */
            public array $reserved = [];

            public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
            {
                $this->reserved[] = $minorUnits;

                return true;
            }

            public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void {}
        };
        $failure = new class implements AiAdapter
        {
            public function generate(array $request, array $route): array
            {
                throw new RuntimeException('Transient provider failure.');
            }
        };
        $success = new class implements AiAdapter
        {
            public function generate(array $request, array $route): array
            {
                return ['status' => 'complete', 'cost_minor' => 9, 'output' => 'unvalidated'];
            }
        };
        $routes = [$this->route(), array_replace($this->route(), [
            'id' => 'candidate-2', 'adapter_id' => 'success', 'max_reservation_minor' => 50,
        ])];
        $routes[0]['adapter_id'] = 'failure';
        $request = array_replace($this->request(), ['max_cost_minor' => 90, 'fallback_policy' => 'compatible_only']);
        $gateway = new AiGateway(new AiRoutePolicy, $ledger, ['failure' => $failure, 'success' => $success], $this->circuit(), $this->telemetry());

        $result = $gateway->generate($routes, $request, $this->scope());
        self::assertSame('complete', $result['status']);
        self::assertSame('candidate-2', $result['route_id']);
        self::assertSame([40, 50], $ledger->reserved);
        self::assertNull($result['output']);

        $routes[1]['data_regions'] = ['us'];
        self::assertSame('provider_failed', $gateway->generate($routes, $request, $this->scope())['status']);
    }
}
