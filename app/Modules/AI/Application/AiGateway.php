<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiAdapter;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\AI\Infrastructure\DenyingAiCircuitBreaker;
use App\Modules\AI\Infrastructure\DenyingAiTelemetryRecorder;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

/** A bounded proposal gateway; tools and side effects are deliberately outside this path. */
final class AiGateway
{
    /** @param array<string, AiAdapter> $adapters */
    public function __construct(
        private readonly AiRoutePolicy $policy,
        private readonly AiBudgetLedger $budget,
        private readonly array $adapters = [],
        private readonly AiCircuitBreaker $circuit = new DenyingAiCircuitBreaker,
        private readonly AiTelemetryRecorder $telemetry = new DenyingAiTelemetryRecorder,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $routes  Server-owned registry, never model input.
     * @param  array<string, mixed>  $request  Authenticated and policy-validated envelope.
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
        $remaining = $request['max_cost_minor'];
        $attempts = 0;
        $lastFailedRoute = null;
        foreach ($eligible as $route) {
            if ($attempts >= 2 || $route['max_reservation_minor'] > $remaining
                || ! $this->circuit->allows($workspace, $route['id'])) {
                continue;
            }

            $adapter = $this->adapters[$route['adapter_id']] ?? null;
            if (! $adapter instanceof AiAdapter) {
                continue;
            }

            $attempts++;
            $reservation = $route['max_reservation_minor'];
            $attemptId = hash('sha256', $traceId.':'.$route['id'].':'.$route['version']);
            if (! $this->telemetry->begin($workspace, $attemptId, $route, $request)) {
                return ['status' => 'telemetry_unavailable', 'trace_id' => $traceId];
            }

            if (! $this->budget->reserve($workspace, $attemptId, $reservation)) {
                $this->telemetry->finish($workspace, $attemptId, 'budget_denied', null);

                return ['status' => 'budget_denied', 'trace_id' => $traceId];
            }
            $remaining -= $reservation;

            try {
                $result = $adapter->generate($request, $route);
            } catch (\Throwable $error) {
                // Unknown cost after an adapter failure stays reserved until durable reconciliation.
                $lastFailedRoute = $route['id'];
                try {
                    $this->circuit->failed($workspace, $route['id']);
                    $this->telemetry->finish($workspace, $attemptId, 'provider_failed', null);
                } catch (\Throwable) {
                    return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
                }

                if (($request['fallback_policy'] ?? null) !== 'compatible_only') {
                    return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
                }

                continue;
            }

            $status = $result['status'] ?? null;
            $actual = $result['cost_minor'] ?? null;
            if (! in_array($status, ['complete', 'refused', 'incomplete'], true)
                || ! is_int($actual) || $actual < 0 || $actual > $reservation) {
                $this->telemetry->finish($workspace, $attemptId, 'provider_failed', null);

                return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
            }

            try {
                $this->budget->settle($workspace, $attemptId, $actual);
                $this->circuit->succeeded($workspace, $route['id']);
                $this->telemetry->finish($workspace, $attemptId, $status, $actual);
            } catch (\Throwable) {
                return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
            }

            return [
                'status' => $status,
                'trace_id' => $traceId,
                'route_id' => $route['id'],
                'route_version' => $route['version'],
                'cost_minor' => $actual,
                // TASK-0057 must validate schema, semantics and tool policy before any output is released.
                'output' => null,
            ];
        }

        if ($lastFailedRoute !== null) {
            return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $lastFailedRoute];
        }

        return ['status' => 'route_unavailable', 'trace_id' => $traceId];
    }
}
