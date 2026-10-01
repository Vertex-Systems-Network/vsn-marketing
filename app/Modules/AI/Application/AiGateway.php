<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiAdapter;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;

/** A bounded proposal gateway; tools and side effects are deliberately outside this path. */
final class AiGateway
{
    /** @param array<string, AiAdapter> $adapters */
    public function __construct(
        private readonly AiRoutePolicy $policy,
        private readonly AiBudgetLedger $budget,
        private readonly array $adapters = [],
    ) {}

    /**
     * @param list<array<string, mixed>> $routes Server-owned registry, never model input.
     * @param array<string, mixed> $request Authenticated and policy-validated envelope.
     * @return array<string, mixed>
     */
    public function generate(array $routes, array $request, TenantContext $scope): array
    {
        $traceId = $request['trace_id'] ?? null;
        if (! is_string($traceId) || $traceId === '') {
            throw new InvalidArgumentException('AI gateway requires a trace.');
        }

        $workspace = $scope->workspaceId;
        $request['workspace_id'] = $workspace;
        $eligible = $this->policy->eligible($routes, $request);
        foreach ($eligible as $route) {
            $adapter = $this->adapters[$route['adapter_id']] ?? null;
            if (! $adapter instanceof AiAdapter) {
                continue;
            }

            $reservation = $route['max_reservation_minor'];
            if (! $this->budget->reserve($workspace, $traceId, $reservation)) {
                return ['status' => 'budget_denied', 'trace_id' => $traceId];
            }

            try {
                $result = $adapter->generate($request, $route);
                $status = $result['status'] ?? null;
                $actual = $result['cost_minor'] ?? null;
                if (! in_array($status, ['complete', 'refused', 'incomplete'], true)
                    || ! is_int($actual) || $actual < 0 || $actual > $reservation) {
                    throw new RuntimeException('Provider result cannot be safely normalized.');
                }

                $this->budget->settle($workspace, $traceId, $actual);

                return [
                    'status' => $status,
                    'trace_id' => $traceId,
                    'route_id' => $route['id'],
                    'route_version' => $route['version'],
                    'cost_minor' => $actual,
                    // TASK-0057 must validate schema, semantics and tool policy before any output is released.
                    'output' => null,
                ];
            } catch (\Throwable $error) {
                // Unknown cost after an adapter failure stays reserved until durable reconciliation.
                return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
            }
        }

        return ['status' => 'route_unavailable', 'trace_id' => $traceId];
    }
}
