<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineReceipt;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment is required for bounded autonomy replay.');
    }
});

function autonomyReplayWorkspace(string $organization, string $name): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'organization_id' => $organization,
        'name' => $name,
        'slug' => 'autonomy-'.Str::random(14),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function autonomyReplayGoal(string $workspace): array
{
    return [
        'workspace_id' => $workspace,
        'brand_id' => null,
        'policy_version' => 'v1',
        'purpose' => 'campaign_optimization',
        'metric_id' => 'conversion_count',
        'target_count' => 12,
        'expires_at_unix' => 1791507600,
    ];
}

function autonomyReplayRuntime(): BoundedAutonomyOfflineReceipt
{
    $preview = new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['trusted-source'],
        ['conversion_count'],
    );

    return new BoundedAutonomyOfflineReceipt($preview, app(IdempotentExecutor::class));
}

function autonomyReplayActions(): array
{
    return [[
        'tool_id' => 'analytics_read',
        'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['trusted-source'],
        'reason_code' => 'metric_review',
    ]];
}

it('persists one offline receipt for exact replay and denies conflicting evidence without a second attempt', function () {
    $organization = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $organization,
        'name' => 'Autonomy replay evidence fixture',
        'slug' => 'autonomy-org-'.Str::random(12),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $workspace = autonomyReplayWorkspace($organization, 'A');
    $scope = new TenantContext($organization, $workspace, null, 'operator');
    $at = new DateTimeImmutable('2026-10-09T00:00:00+00:00');
    $runtime = autonomyReplayRuntime();

    $first = $runtime->record($scope, 'offline-run-1', autonomyReplayGoal($workspace), autonomyReplayActions(), $at);
    $replayed = $runtime->record($scope, 'offline-run-1', autonomyReplayGoal($workspace), autonomyReplayActions(), $at);
    expect($first)->toBe($replayed)
        ->and($first['execution_authorized'])->toBeFalse()
        ->and($first['stages']['execute'])->toBe('disabled');

    $row = DB::table('idempotency_keys')->where('workspace_id', $workspace)
        ->where('scope', 'ai-offline-autonomy-preview:v1')
        ->where('idempotency_key', 'offline-run-1')->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('completed')
        ->and((int) $row->attempts)->toBe(1)
        ->and(DB::table('audit_events')->where('workspace_id', $workspace)
            ->where('action', IdempotentExecutor::AUDIT_COMPLETED)->count())->toBe(1);

    expect(fn () => $runtime->record($scope, 'offline-run-1',
        array_replace(autonomyReplayGoal($workspace), ['target_count' => 99]), autonomyReplayActions(), $at))
        ->toThrow(InvalidArgumentException::class, 'Conflicting autonomy replay');
    expect(fn () => $runtime->record(new TenantContext($organization, $workspace, null, 'imposter'),
        'offline-run-1', autonomyReplayGoal($workspace), autonomyReplayActions(), $at))
        ->toThrow(InvalidArgumentException::class, 'Conflicting autonomy replay');

    expect(DB::table('idempotency_keys')->where('workspace_id', $workspace)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('workspace_id', $workspace)
            ->where('action', IdempotentExecutor::AUDIT_COMPLETED)->count())->toBe(1);
});

it('keeps identical run IDs isolated across workspaces and refuses processing claims', function () {
    $organization = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $organization,
        'name' => 'Autonomy replay tenant fixture',
        'slug' => 'autonomy-org-'.Str::random(12),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $a = autonomyReplayWorkspace($organization, 'A');
    $b = autonomyReplayWorkspace($organization, 'B');
    $runtime = autonomyReplayRuntime();
    $at = new DateTimeImmutable('2026-10-09T00:00:00+00:00');

    $one = $runtime->record(new TenantContext($organization, $a, null, 'operator'), 'shared-run',
        autonomyReplayGoal($a), autonomyReplayActions(), $at);
    $two = $runtime->record(new TenantContext($organization, $b, null, 'operator'), 'shared-run',
        autonomyReplayGoal($b), autonomyReplayActions(), $at);
    expect($one['snapshot_sha256'])->not->toBe($two['snapshot_sha256'])
        ->and(DB::table('idempotency_keys')->where('idempotency_key', 'shared-run')->count())->toBe(2);

    DB::table('idempotency_keys')->insert([
        'workspace_id' => $a,
        'scope' => 'ai-offline-autonomy-preview:v1',
        'idempotency_key' => 'in-progress-run',
        'status' => 'processing',
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(fn () => $runtime->record(new TenantContext($organization, $a, null, 'operator'),
        'in-progress-run', autonomyReplayGoal($a), autonomyReplayActions(), $at))
        ->toThrow(RuntimeException::class, 'already in progress');
    expect(DB::table('idempotency_keys')->where('workspace_id', $a)
        ->where('idempotency_key', 'in-progress-run')->value('status'))->toBe('processing');
});
