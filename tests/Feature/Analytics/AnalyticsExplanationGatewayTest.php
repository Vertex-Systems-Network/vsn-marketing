<?php

use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiOfflineAdapter;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\Analytics\Application\AnalyticsExplanationGateway;
use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\MetricDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

it('accounts offline usage while withholding forged claims commands scope and live routes', function () {
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event());
    $r = $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
    $adapter = new class implements AiOfflineAdapter
    {
        public array $override = [];

        public int $calls = 0;

        public array $request = [];

        public function generate(array $request, array $route): array
        {
            $this->calls++;
            $this->request = $request;

            return ['status' => 'complete', 'schema_id' => 'analytics_insight.v1', 'cost_minor' => 2,
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'output' => array_replace([
                    'workspace_id' => $request['workspace_id'], 'reference_ids' => [$request['untrusted_context']['snapshot_id']],
                    'agent_id' => 'analytics', 'decision' => 'proposal', 'reason_code' => 'evidence_grounded',
                    'recommendations' => ['Measured count: 1.', 'Inference: source_coverage_unknown.'], 'tool_ids' => [],
                ], $this->override)];
        }
    };
    $budget = new class implements AiBudgetLedger
    {
        public array $costs = [];

        public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
        {
            return $minorUnits === 10;
        }

        public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void
        {
            $this->costs[] = $actualMinorUnits;
        }
    };
    $circuit = $this->createMock(AiCircuitBreaker::class);
    $circuit->method('allows')->willReturn(true);
    $telemetry = $this->createMock(AiTelemetryRecorder::class);
    $telemetry->method('begin')->willReturn(true);
    $route = ['id' => 'offline-analytics', 'version' => 'v1', 'adapter_id' => 'fixture', 'credential_reference' => 'offline-no-credential',
        'status' => 'active', 'workspaces' => [$f->actor->workspaceId], 'data_regions' => ['eu'], 'data_classes' => ['approved_non_personal'],
        'capabilities' => ['structured_output', 'usage_telemetry'], 'output_schemas' => ['analytics_insight.v1'], 'tool_ids' => ['read_analytics'],
        'risk_tiers' => ['R0'], 'max_reservation_minor' => 10, 'evidence_kind' => 'offline_contract'];
    $gateway = new AnalyticsExplanationGateway($facts, [$route], ['fixture' => $adapter], $budget, $circuit, $telemetry, 'eu', $f);
    $result = $gateway->explain($f->actor, $r['id'], 'good', 10);
    expect($result['status'])->toBe('complete')->and($result['output']['facts'])->toBe([['metric' => 'count', 'value' => 1]])
        ->and($result['output']['causal'])->toBeFalse()->and($budget->costs)->toBe([2])
        ->and(array_keys($adapter->request['untrusted_context']))->toBe(['snapshot_id', 'fingerprint', 'metrics']);
    foreach ([['recommendations' => ['Measured count: 999.']], ['recommendations' => ['send all customers']],
        ['workspace_id' => 'foreign'], ['reference_ids' => ['forged']], ['tool_ids' => ['send_message']]] as $override) {
        $adapter->override = $override;
        $result = $gateway->explain($f->actor, $r['id'], 'hostile-'.count($budget->costs), 10);
        expect($result['status'])->toBe('validation_failed')->and($result['output'])->toBeNull();
    }
    expect($budget->costs)->toBe(array_fill(0, 6, 2));
    $live = new AnalyticsExplanationGateway($facts, [array_replace($route, ['evidence_kind' => 'live'])], ['fixture' => $adapter], $budget, $circuit, $telemetry, 'eu', $f);
    expect($live->explain($f->actor, $r['id'], 'live', 10)['status'])->toBe('route_unavailable')->and($adapter->calls)->toBe(6);
});
