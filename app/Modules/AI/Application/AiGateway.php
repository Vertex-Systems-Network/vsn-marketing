<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiRoutePolicy;
use App\Modules\AI\Domain\Contracts\AiAdapter;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiGatewayOutputValidator;
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
        private readonly ?AiGatewayOutputValidator $outputs = null,
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
            $usage = $result['usage'] ?? null;
            if (! in_array($status, ['complete', 'refused', 'incomplete', 'cancelled'], true)
                || ! is_int($actual) || $actual < 0 || $actual > $reservation
                || ! is_array($usage) || ! is_int($usage['input_tokens'] ?? null)
                || ! is_int($usage['output_tokens'] ?? null)
                || $usage['input_tokens'] < 0 || $usage['output_tokens'] < 0) {
                $this->telemetry->finish($workspace, $attemptId, 'provider_failed', null);

                return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
            }

            try {
                $this->budget->settle($workspace, $attemptId, $actual);
            } catch (\Throwable) {
                return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
            }

            $validatedOutput = null;
            $validation = 'withheld';
            if ($status === 'complete' && $this->outputs !== null) {
                try {
                    $validatedOutput = $this->outputs->validate($result, $request, $scope);
                    $validation = 'validated';
                } catch (\Throwable) {
                    $status = 'validation_failed';
                    $validation = 'rejected';
                }
            }

            try {
                if ($status === 'validation_failed') {
                    $this->circuit->failed($workspace, $route['id']);
                } else {
                    $this->circuit->succeeded($workspace, $route['id']);
                }
                $this->telemetry->finish($workspace, $attemptId, $status, $actual, $usage['input_tokens'], $usage['output_tokens']);
            } catch (\Throwable) {
                return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $route['id']];
            }

            return [
                'status' => $status,
                'trace_id' => $traceId,
                'route_id' => $route['id'],
                'route_version' => $route['version'],
                'cost_minor' => $actual,
                'usage' => $usage,
                'prompt_id' => $request['prompt_id'], 'prompt_version' => $request['prompt_version'],
                'schema_id' => $request['output_schema_id'],
                'context_manifest_sha256' => $request['context_manifest_sha256'],
                'validation_status' => $validation,
                'output' => $validatedOutput,
            ];
        }

        if ($lastFailedRoute !== null) {
            return ['status' => 'provider_failed', 'trace_id' => $traceId, 'route_id' => $lastFailedRoute];
        }

        return ['status' => 'route_unavailable', 'trace_id' => $traceId];
    }
}
