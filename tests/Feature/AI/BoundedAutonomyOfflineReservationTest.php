<?php

use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineReservation;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function autonomyOfflineDbScope(): TenantContext
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Autonomy fixture', 'slug' => 'autonomy-db-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org, 'name' => 'Autonomy workspace',
        'slug' => 'autonomy-db-'.Str::random(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return new TenantContext($org, $workspace, null, 'operator');
}

function autonomyOfflineDbTime(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
}

function autonomyOfflineDbPreview(TenantContext $scope, string $runId): array
{
    $at = autonomyOfflineDbTime();

    return (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['approved-source'], ['count'], 1,
    ))->preview($scope, $runId, [
        'workspace_id' => $scope->workspaceId,
        'brand_id' => $scope->brandId,
        'policy_version' => 'v1',
        'purpose' => 'campaign_optimization',
        'metric_id' => 'count',
        'target_count' => 2,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read',
        'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['approved-source'],
        'reason_code' => 'metric_review',
    ]], $at);
}

function autonomyOfflineDbLimits(TenantContext $scope, bool $stopped = false): void
{
    $at = autonomyOfflineDbTime();
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $scope->workspaceId,
        'period_utc' => $at->format('Y-m-d'),
        'policy_version' => 'v1',
        'workspace_stopped' => $stopped,
        'max_actions' => 2,
        'max_tokens' => 100,
        'max_volume' => 5,
        'max_cost_minor' => 20,
        'max_attempts' => 2,
        'policy_expires_at' => $at->modify('+20 minutes')->format('Y-m-d H:i:s'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function autonomyOfflineDbEstimate(): array
{
    return ['actions' => 1, 'tokens' => 60, 'volume' => 2, 'cost_minor' => 11, 'attempts' => 1];
}

it('denies unconfigured global stop and absent workspace quota without writing reservations', function () {
    $scope = autonomyOfflineDbScope();
    $preview = autonomyOfflineDbPreview($scope, 'offline-a');
    $service = new DatabaseBoundedAutonomyOfflineReservation;
    $first = $service->reserve($scope, $preview, autonomyOfflineDbEstimate(), autonomyOfflineDbTime());
    expect($first['status'])->toBe('held_offline')
        ->and($first['reason_code'])->toBe('global_stop_unconfigured')
        ->and($first['execution_authorized'])->toBeFalse();

    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $stop = $service->reserve($scope, $preview, autonomyOfflineDbEstimate(), autonomyOfflineDbTime());
    expect($stop['reason_code'])->toBe('global_emergency_stop');

    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => false]);
    $missing = $service->reserve($scope, $preview, autonomyOfflineDbEstimate(), autonomyOfflineDbTime());
    expect($missing['reason_code'])->toBe('workspace_quota_unconfigured')
        ->and(DB::table('ai_autonomy_offline_reservations')->count())->toBe(0);
});

it('enforces workspace stop, atomic quota accounting, duplicate replay and exhausted dimensions', function () {
    $scope = autonomyOfflineDbScope();
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    autonomyOfflineDbLimits($scope, true);
    $service = new DatabaseBoundedAutonomyOfflineReservation;
    $estimate = autonomyOfflineDbEstimate();
    $preview = autonomyOfflineDbPreview($scope, 'offline-a');

    $held = $service->reserve($scope, $preview, $estimate, autonomyOfflineDbTime());
    expect($held['reason_code'])->toBe('workspace_emergency_stop');

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => false]);
    $recorded = $service->reserve($scope, $preview, $estimate, autonomyOfflineDbTime());
    expect($recorded['status'])->toBe('reserved_offline')
        ->and($recorded['offline_resource_reservation_recorded'])->toBeTrue()
        ->and($recorded['execution_authorized'])->toBeFalse()
        ->and($recorded['promotion_authorized'])->toBeFalse();

    $repeat = $service->reserve($scope, $preview, $estimate, autonomyOfflineDbTime());
    expect($repeat['reason_code'])->toBe('run_already_recorded');
    $overLimit = $service->reserve($scope, autonomyOfflineDbPreview($scope, 'offline-b'), $estimate, autonomyOfflineDbTime());
    expect($overLimit['status'])->toBe('held_offline')
        ->and($overLimit['reason_code'])->toBe('tokens_limit_reached');

    $usage = DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)->first();
    expect((int) $usage->used_actions)->toBe(1)
        ->and((int) $usage->used_tokens)->toBe(60)
        ->and((int) $usage->used_volume)->toBe(2)
        ->and((int) $usage->reserved_cost_minor)->toBe(11)
        ->and((int) $usage->used_attempts)->toBe(1)
        ->and(DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $scope->workspaceId)->count())->toBe(1);

    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
    expect($service->reserve($scope, autonomyOfflineDbPreview($scope, 'offline-c'), $estimate, autonomyOfflineDbTime())['reason_code'])
        ->toBe('global_emergency_stop');
});

it('rejects actor spoofing, forged execution and stale policy with no quota mutations', function () {
    $scope = autonomyOfflineDbScope();
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    autonomyOfflineDbLimits($scope);
    $service = new DatabaseBoundedAutonomyOfflineReservation;
    $preview = autonomyOfflineDbPreview($scope, 'offline-one');
    $tampered = array_replace($preview, ['execution_authorized' => true]);

    expect(fn () => $service->reserve($scope, $tampered, autonomyOfflineDbEstimate(), autonomyOfflineDbTime()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->reserve(new TenantContext($scope->organizationId, $scope->workspaceId, null, 'imposter'),
        $preview, autonomyOfflineDbEstimate(), autonomyOfflineDbTime()))->toThrow(InvalidArgumentException::class);

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['policy_expires_at' => autonomyOfflineDbTime()->modify('-1 minute')->format('Y-m-d H:i:s')]);
    expect(fn () => $service->reserve($scope, $preview, autonomyOfflineDbEstimate(), autonomyOfflineDbTime()))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_offline_reservations')->count())->toBe(0)
        ->and((int) DB::table('ai_autonomy_workspace_quotas')
            ->where('workspace_id', $scope->workspaceId)->value('used_tokens'))->toBe(0);
});
