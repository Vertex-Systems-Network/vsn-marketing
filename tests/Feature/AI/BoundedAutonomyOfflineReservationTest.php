<?php

use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineReservation;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomySafetySnapshotSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function offlineSafetyAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
}

function offlineSafetyWorkspace(): TenantContext
{
    $organization = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $organization, 'name' => 'Offline safety fixture',
        'slug' => 'autonomy-safety-'.Str::random(12), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $organization,
        'name' => 'Offline safety workspace', 'slug' => 'autonomy-safety-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return new TenantContext($organization, $workspace, null, 'operator');
}

function offlineSafetySeed(TenantContext $scope, bool $withGlobal = true): void
{
    if ($withGlobal) {
        DB::table('ai_autonomy_global_stops')->insert([
            'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $scope->workspaceId,
        'period_utc' => offlineSafetyAt()->format('Y-m-d'),
        'policy_version' => 'v1', 'workspace_stopped' => false,
        'max_actions' => 1, 'max_tokens' => 100, 'max_volume' => 10,
        'max_cost_minor' => 50, 'max_attempts' => 1,
        'policy_expires_at' => '2026-10-09 10:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function offlineSafetyPreview(TenantContext $scope, string $runId = 'offline-1'): array
{
    $at = offlineSafetyAt();

    return (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['analytics-fixture'], ['review_count'], 1,
    ))->preview($scope, $runId, [
        'workspace_id' => $scope->workspaceId, 'brand_id' => $scope->brandId,
        'policy_version' => 'v1', 'purpose' => 'campaign_optimization',
        'metric_id' => 'review_count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['analytics-fixture'], 'reason_code' => 'metric_review',
    ]], $at);
}

function offlineSafetyEstimate(): array
{
    return ['actions' => 1, 'tokens' => 30, 'volume' => 2, 'cost_minor' => 20, 'attempts' => 1];
}

it('denies missing control authority, even when a quota row exists', function () {
    $scope = offlineSafetyWorkspace();
    offlineSafetySeed($scope, false);
    $source = new DatabaseBoundedAutonomySafetySnapshotSource;
    expect($source->current($scope, offlineSafetyAt()))->toBeNull();
    $r = (new DatabaseBoundedAutonomyOfflineReservation)->reserve(
        $scope, offlineSafetyPreview($scope), offlineSafetyEstimate(), offlineSafetyAt(),
    );
    expect($r['status'])->toBe('held_offline')
        ->and($r['execution_authorized'])->toBeFalse()
        ->and(DB::table('ai_autonomy_offline_reservations')->count())->toBe(0);
});

it('reserves a single offline attempt with exact replay and no duplicate quotas', function () {
    $scope = offlineSafetyWorkspace();
    offlineSafetySeed($scope);
    $ledger = new DatabaseBoundedAutonomyOfflineReservation;
    $preview = offlineSafetyPreview($scope);
    $first = $ledger->reserve($scope, $preview, offlineSafetyEstimate(), offlineSafetyAt());
    $replay = $ledger->reserve($scope, $preview, offlineSafetyEstimate(), offlineSafetyAt());
    expect($first)->toBe($replay)
        ->and($first['status'])->toBe('reserved_offline')
        ->and($first['execution_authorized'])->toBeFalse()
        ->and($first['promotion_authorized'])->toBeFalse();
    $quota = DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)->first();
    expect((int) $quota->used_actions)->toBe(1)
        ->and((int) $quota->used_tokens)->toBe(30)
        ->and((int) $quota->used_volume)->toBe(2)
        ->and((int) $quota->reserved_cost_minor)->toBe(20)
        ->and((int) $quota->used_attempts)->toBe(1)
        ->and(DB::table('ai_autonomy_offline_reservations')->count())->toBe(1);
    $denied = $ledger->reserve($scope, offlineSafetyPreview($scope, 'offline-2'), offlineSafetyEstimate(), offlineSafetyAt());
    expect($denied['status'])->toBe('held_offline')
        ->and($denied['reason_code'])->toBe('actions_limit_reached')
        ->and(DB::table('ai_autonomy_offline_reservations')->count())->toBe(1);
});

it('rejects changed actor, source action or replay cost and rechecks both emergency stops', function () {
    $scope = offlineSafetyWorkspace();
    offlineSafetySeed($scope);
    $ledger = new DatabaseBoundedAutonomyOfflineReservation;
    $preview = offlineSafetyPreview($scope);
    $ledger->reserve($scope, $preview, offlineSafetyEstimate(), offlineSafetyAt());

    expect(fn () => $ledger->reserve(new TenantContext($scope->organizationId, $scope->workspaceId, null, 'imposter'),
        $preview, offlineSafetyEstimate(), offlineSafetyAt()))->toThrow(InvalidArgumentException::class);
    $altered = offlineSafetyEstimate();
    $altered['cost_minor'] = 19;
    expect(fn () => $ledger->reserve($scope, $preview, $altered, offlineSafetyAt()))
        ->toThrow(InvalidArgumentException::class, 'Conflicting offline autonomy reservation');
    $bad = $preview;
    $bad['actions'][0]['effect'] = 'send';
    expect(fn () => $ledger->reserve($scope, $bad, offlineSafetyEstimate(), offlineSafetyAt()))
        ->toThrow(InvalidArgumentException::class);

    expect($ledger->recheckHeld($scope, $preview, offlineSafetyAt())['status'])->toBe('reserved_offline');
    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
    expect($ledger->recheckHeld($scope, $preview, offlineSafetyAt())['reason_code'])->toBe('global_emergency_stop');
    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => false]);
    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => true]);
    expect($ledger->recheckHeld($scope, $preview, offlineSafetyAt())['reason_code'])->toBe('workspace_emergency_stop');
});
