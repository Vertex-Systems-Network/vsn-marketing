<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineEvaluation;
use App\Modules\AI\Application\BoundedAutonomyOfflineReceipt;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyObservationSource;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment is required for autonomy observation replay.');
    }
});

it('persists read-only verified observation exactly once and rejects altered evidence without promotion', function () {
    $organization = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $organization, 'name' => 'Autonomy observation fixture',
        'slug' => 'autonomy-observation-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $organization, 'name' => 'Autonomy observation workspace',
        'slug' => 'autonomy-observation-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $scope = new TenantContext($organization, $workspace, null, 'operator');
    $at = new DateTimeImmutable('2026-10-09T00:00:00+00:00');
    $goal = [
        'workspace_id' => $workspace, 'brand_id' => null, 'policy_version' => 'v1',
        'purpose' => 'campaign_optimization', 'metric_id' => 'verified_conversions',
        'target_count' => 12, 'expires_at_unix' => 1791507600,
    ];
    $actions = [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]];

    $facts = (object) ['current' => [
        'workspace_id' => $workspace, 'brand_id' => null, 'source_id' => 'source-1',
        'metric_id' => 'verified_conversions', 'count' => 16,
        'observed_at_unix' => $at->getTimestamp(), 'evidence_sha256' => str_repeat('f', 64),
    ]];
    $source = $this->createMock(BoundedAutonomyObservationSource::class);
    $source->method('verifiedCount')->willReturnCallback(static function (TenantContext $subject, string $sourceId, string $metricId, DateTimeImmutable $requestedAt) use ($facts): ?array {
        return $facts->current;
    });
    $preview = new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['verified_conversions'],
    );
    $executor = app(IdempotentExecutor::class);
    $runner = new BoundedAutonomyOfflineEvaluation(
        new BoundedAutonomyOfflineReceipt($preview, $executor), $source, $executor,
    );
    $first = $runner->evaluate($scope, 'verified-run-1', $goal, $actions, 'source-1', $at);
    $duplicate = $runner->evaluate($scope, 'verified-run-1', $goal, $actions, 'source-1', $at);
    expect($first)->toBe($duplicate)
        ->and($first['status'])->toBe('evaluated_offline')
        ->and($first['decision'])->toBe('target_met_operator_review')
        ->and($first['causal_lift_proven'])->toBeFalse()
        ->and($first['promotion_authorized'])->toBeFalse()
        ->and($first['execution_authorized'])->toBeFalse();

    $stored = DB::table('idempotency_keys')->where('workspace_id', $workspace)->get();
    expect($stored)->toHaveCount(2)
        ->and($stored->every(static fn ($row): bool => $row->status === 'completed' && (int) $row->attempts === 1))->toBeTrue()
        ->and(DB::table('audit_events')->where('workspace_id', $workspace)
            ->where('action', IdempotentExecutor::AUDIT_COMPLETED)->count())->toBe(2);

    $facts->current['count'] = 20;
    expect(fn () => $runner->evaluate($scope, 'verified-run-1', $goal, $actions, 'source-1', $at))
        ->toThrow(InvalidArgumentException::class, 'Conflicting or tampered autonomy observation replay');
    expect(DB::table('idempotency_keys')->where('workspace_id', $workspace)->count())->toBe(2);
});
