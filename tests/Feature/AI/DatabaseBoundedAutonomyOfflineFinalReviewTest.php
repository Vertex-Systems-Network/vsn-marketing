<?php

use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyApprovalSource;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineFinalReview;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function offlineFinalReviewFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Final review org',
        'slug' => 'final-review-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Final review workspace',
        'slug' => 'final-review-'.Str::random(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $actor = new TenantContext($org, $ws, null, 'operator');
    $preview = (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['snapshot-a'], ['count'], 1,
    ))->preview($actor, 'run-a', [
        'workspace_id' => $ws, 'brand_id' => null, 'policy_version' => 'v1',
        'purpose' => 'campaign_optimization', 'metric_id' => 'count', 'target_count' => 1,
        'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['snapshot-a'], 'reason_code' => 'metric_review',
    ]], $at);

    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_workspace_quotas')->insert([
        'workspace_id' => $ws, 'period_utc' => '2026-10-09', 'policy_version' => 'v1',
        'workspace_stopped' => false, 'max_actions' => 2, 'max_tokens' => 100,
        'max_volume' => 10, 'max_cost_minor' => 30, 'max_attempts' => 2,
        'used_actions' => 1, 'used_tokens' => 20, 'used_volume' => 3,
        'reserved_cost_minor' => 12, 'spent_cost_minor' => 0, 'used_attempts' => 1,
        'policy_expires_at' => '2026-10-09 12:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_offline_reservations')->insert([
        'workspace_id' => $ws, 'period_utc' => '2026-10-09', 'run_id' => 'run-a',
        'brand_id' => null, 'actor_id' => 'operator',
        'snapshot_sha256' => $preview['snapshot_sha256'], 'policy_version' => 'v1',
        'actions' => 1, 'tokens' => 20, 'volume' => 3, 'cost_minor' => 12,
        'attempts' => 1, 'status' => 'offline_reserved',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $binding = [
        'audience_sha256' => str_repeat('b', 64),
        'content_sha256' => str_repeat('c', 64),
        'destination_sha256' => str_repeat('d', 64),
        'max_cost_minor' => 15, 'max_volume' => 4,
        'not_before_unix' => $at->getTimestamp() - 60,
        'expires_at_unix' => $at->getTimestamp() + 300,
    ];
    $decision = [
        'decision_id' => 'decision-1', 'workspace_id' => $ws, 'brand_id' => null,
        'run_id' => 'run-a', 'snapshot_sha256' => $preview['snapshot_sha256'],
        'policy_version' => 'v1',
        'audience_sha256' => $binding['audience_sha256'],
        'content_sha256' => $binding['content_sha256'],
        'destination_sha256' => $binding['destination_sha256'],
        'max_cost_minor' => 15, 'max_volume' => 4,
        'not_before_unix' => $binding['not_before_unix'],
        'expires_at_unix' => $binding['expires_at_unix'],
        'approved_at_unix' => $at->getTimestamp() - 30,
        'approver_id' => 'human-owner', 'outcome' => 'approved',
    ];

    return [$actor, $preview, $binding, $at, $decision,
        ['actions' => 1, 'tokens' => 20, 'volume' => 3, 'cost_minor' => 12, 'attempts' => 1]];
}

function offlineFinalSource(object $state): DatabaseBoundedAutonomyOfflineFinalReview
{
    $source = Mockery::mock(BoundedAutonomyApprovalSource::class);
    $source->shouldReceive('latest')->andReturnUsing(
        static fn (TenantContext $scope, string $runId, DateTimeImmutable $at): ?array => $state->value,
    );

    return new DatabaseBoundedAutonomyOfflineFinalReview($source);
}

it('rechecks reservation and independently sourced approval but never authorizes side effects', function () {
    [$scope, $preview, $binding, $at, $decision, $estimate] = offlineFinalReviewFixture();
    $source = (object) ['value' => $decision];
    $gate = offlineFinalSource($source);
    $result = $gate->inspect($scope, $preview, $binding, $estimate, $at);
    expect($result['status'])->toBe('offline_final_review_passed')
        ->and($result['reservation_verified'])->toBeTrue()
        ->and($result['execution_authorized'])->toBeFalse()
        ->and($result['promotion_authorized'])->toBeFalse();

    $source->value['outcome'] = 'revoked';
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])->toBe('approval_revoked');
    $source->value = null;
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('independent_approval_unavailable');
    expect(DB::table('ai_autonomy_offline_reservations')->where('run_id', 'run-a')->value('status'))
        ->toBe('offline_reserved');
});

it('refuses global/workspace stops, unknown outcomes, expired policy and exhausted trust', function () {
    [$scope, $preview, $binding, $at, $decision, $estimate] = offlineFinalReviewFixture();
    $gate = offlineFinalSource((object) ['value' => $decision]);
    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('global_emergency_stop_or_unknown');

    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => false]);
    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => true]);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('workspace_emergency_stop_or_unknown');

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['workspace_stopped' => false, 'policy_expires_at' => '2026-10-09 08:59:00']);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('workspace_policy_changed_or_expired');

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['policy_expires_at' => '2026-10-09 12:00:00']);
    DB::table('ai_autonomy_offline_reservations')->where('run_id', 'run-a')
        ->update(['status' => 'held_by_emergency_stop']);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('reservation_not_active');
    DB::table('ai_autonomy_offline_reservations')->where('run_id', 'run-a')
        ->update(['status' => 'external_unconfirmed']);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('reservation_not_active');
});

it('holds when concurrent offline reservations have pushed trusted quota counters over their ceiling', function () {
    [$scope, $preview, $binding, $at, $decision, $estimate] = offlineFinalReviewFixture();
    $gate = offlineFinalSource((object) ['value' => $decision]);

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['reserved_cost_minor' => 31]);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('current_budget_exceeded');

    DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
        ->update(['reserved_cost_minor' => 12, 'used_tokens' => 101]);
    expect($gate->inspect($scope, $preview, $binding, $estimate, $at)['reason_code'])
        ->toBe('current_budget_exceeded');
});

it('denies forged actors, changed reservation, invalid estimates and insufficient approval budgets', function () {
    [$scope, $preview, $binding, $at, $decision, $estimate] = offlineFinalReviewFixture();
    $gate = offlineFinalSource((object) ['value' => $decision]);

    expect(fn () => $gate->inspect(new TenantContext($scope->organizationId, $scope->workspaceId, null, 'other'), $preview, $binding, $estimate, $at))
        ->toThrow(InvalidArgumentException::class);
    $otherOrg = new TenantContext((string) Str::uuid(), $scope->workspaceId, null, $scope->actorId);
    $forged = $preview;
    $forged['tenant'] = $otherOrg->toArray();
    expect(fn () => $gate->inspect($otherOrg, $forged, $binding, $estimate, $at))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $gate->inspect($scope, $preview, $binding, array_replace($estimate, ['send' => 1]), $at))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $gate->inspect($scope, array_replace($preview, ['actions' => []]), $binding, $estimate, $at))
        ->toThrow(InvalidArgumentException::class);
    $escalated = $preview;
    $escalated['actions'][0]['effect'] = 'send';
    expect(fn () => $gate->inspect($scope, $escalated, $binding, $estimate, $at))
        ->toThrow(InvalidArgumentException::class);

    $small = array_replace($binding, ['max_cost_minor' => 10]);
    expect($gate->inspect($scope, $preview, $small, $estimate, $at)['status'])
        ->toBe('held_offline');

    DB::table('ai_autonomy_offline_reservations')->where('run_id', 'run-a')
        ->update(['snapshot_sha256' => str_repeat('f', 64)]);
    expect(fn () => $gate->inspect($scope, $preview, $binding, $estimate, $at))
        ->toThrow(InvalidArgumentException::class);
});
