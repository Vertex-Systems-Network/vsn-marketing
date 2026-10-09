<?php

use App\Modules\AI\Application\BoundedAutonomyHumanApprovalRecorder;
use App\Modules\AI\Application\BoundedAutonomyOfflineApprovalReview;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyApprovalSource;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function humanApprovalFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Human approval fixture',
        'slug' => 'human-ai-'.Str::random(12), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org, 'name' => 'Human approval workspace',
        'slug' => 'human-ai-'.Str::random(12), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ai_autonomy_global_stops')->insert([
        'id' => 'global', 'stopped' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $requester = User::query()->create([
        'name' => 'Requester', 'email' => Str::random(12).'@example.test', 'password' => Str::random(24),
    ]);
    $approver = User::query()->create([
        'name' => 'Approver', 'email' => Str::random(12).'@example.test', 'password' => Str::random(24),
    ]);
    $roleManager = app(WorkspaceRoleManager::class);
    $member = $roleManager->addMember($approver, $ws);
    $role = $roleManager->createRole($ws, 'approver', 'Human AI approver');
    $roleManager->assignRole($member, $role);
    $roleManager->grantPermission($role, PermissionCatalog::AI_APPROVE);
    $at = new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    $scope = new TenantContext($org, $ws, null, (string) $requester->getKey());
    $preview = (new BoundedAutonomyPreview(
        ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
        ['source-1'], ['count'], 1,
    ))->preview($scope, 'run-1', [
        'workspace_id' => $ws, 'brand_id' => null, 'policy_version' => 'v1',
        'purpose' => 'campaign_optimization', 'metric_id' => 'count',
        'target_count' => 1, 'expires_at_unix' => $at->getTimestamp() + 3600,
    ], [[
        'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
        'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
    ]], $at);
    $binding = [
        'audience_sha256' => str_repeat('b', 64),
        'content_sha256' => str_repeat('c', 64),
        'destination_sha256' => str_repeat('d', 64),
        'max_cost_minor' => 10, 'max_volume' => 1,
        'not_before_unix' => $at->getTimestamp() - 5,
        'expires_at_unix' => $at->getTimestamp() + 300,
    ];

    return [$requester, $approver, $scope, $preview, $binding, $at, $role];
}

it('records only a separately authenticated current approver and never grants execution', function () {
    [$requester, $approver, $scope, $preview, $binding, $at] = humanApprovalFixture();
    $s = app(BoundedAutonomyHumanApprovalRecorder::class);
    $this->actingAs($approver);
    $r = $s->record($approver, $scope, $preview, $binding, 'approved', $at);
    expect($r['status'])->toBe('decision_recorded_offline')
        ->and($r['execution_authorized'])->toBeFalse()
        ->and($r['promotion_authorized'])->toBeFalse()
        ->and(DB::table('ai_autonomy_offline_approval_decisions')->count())->toBe(1);

    $s->record($approver, $scope, $preview, $binding, 'revoked', $at);
    expect(DB::table('ai_autonomy_offline_approval_decisions')->count())->toBe(2)
        ->and(DB::table('ai_autonomy_offline_approval_decisions')
            ->orderByDesc('sequence')->value('outcome'))->toBe('revoked');
});

it('denies session impersonation, missing authority, self-approval and forged cost', function () {
    [$requester, $approver, $scope, $preview, $binding, $at, $role] = humanApprovalFixture();
    $s = app(BoundedAutonomyHumanApprovalRecorder::class);
    $this->actingAs($requester);
    expect(fn () => $s->record($approver, $scope, $preview, $binding, 'approved', $at))
        ->toThrow(InvalidArgumentException::class);
    $this->actingAs($approver);
    expect(fn () => $s->record($approver, new TenantContext(
        $scope->organizationId, $scope->workspaceId, null, (string) $approver->getKey(),
    ), $preview, $binding, 'approved', $at))->toThrow(InvalidArgumentException::class);
    expect(fn () => $s->record($approver, $scope, $preview,
        array_replace($binding, ['max_cost_minor' => 1000000001]), 'approved', $at))
        ->toThrow(InvalidArgumentException::class);

    DB::table('workspace_role_permissions')->where('workspace_role_id', $role)
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    expect(fn () => $s->record($approver, $scope, $preview, $binding, 'approved', $at))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_offline_approval_decisions')->count())->toBe(0);
});

it('rejects revocation without evidence or unknown verdicts', function () {
    [$requester, $approver, $scope, $preview, $binding, $at] = humanApprovalFixture();
    $this->actingAs($approver);
    $s = app(BoundedAutonomyHumanApprovalRecorder::class);
    expect(fn () => $s->record($approver, $scope, $preview, $binding, 'revoked', $at))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $s->record($approver, $scope, $preview, $binding, 'send_now', $at))
        ->toThrow(InvalidArgumentException::class);
});

it('the independent reader revalidates latest human approval and revocation after recording', function () {
    [$requester, $approver, $scope, $preview, $binding, $at, $role] = humanApprovalFixture();
    $this->actingAs($approver);
    $writer = app(BoundedAutonomyHumanApprovalRecorder::class);
    $reader = new BoundedAutonomyOfflineApprovalReview(
        new DatabaseBoundedAutonomyApprovalSource(app(WorkspaceAuthorizer::class)),
    );
    $writer->record($approver, $scope, $preview, $binding, 'approved', $at);
    $matched = $reader->inspect($scope, $preview, $binding, $at);
    expect($matched['status'])->toBe('approval_matched_offline')
        ->and($matched['execution_authorized'])->toBeFalse();

    $writer->record($approver, $scope, $preview, $binding, 'revoked', $at);
    expect($reader->inspect($scope, $preview, $binding, $at)['reason_code'])->toBe('approval_revoked');

    DB::table('workspace_role_permissions')->where('workspace_role_id', $role)
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    expect($reader->inspect($scope, $preview, $binding, $at)['reason_code'])
        ->toBe('independent_approval_unavailable');
});

it('serializes human approval decisions with global emergency stop and allows revocation during stop', function () {
    [$requester, $approver, $scope, $preview, $binding, $at] = humanApprovalFixture();
    $this->actingAs($approver);
    $writer = app(BoundedAutonomyHumanApprovalRecorder::class);
    $first = $writer->record($approver, $scope, $preview, $binding, 'approved', $at);
    expect($first['status'])->toBe('decision_recorded_offline');

    DB::table('ai_autonomy_global_stops')->where('id', 'global')->update(['stopped' => true]);
    expect(fn () => $writer->record($approver, $scope, $preview, $binding, 'approved', $at))
        ->toThrow(InvalidArgumentException::class, 'Global autonomy stop');
    $revoked = $writer->record($approver, $scope, $preview, $binding, 'revoked', $at);
    expect($revoked['outcome'])->toBe('revoked')
        ->and($revoked['execution_authorized'])->toBeFalse();

    DB::table('ai_autonomy_global_stops')->where('id', 'global')->delete();
    expect(fn () => $writer->record($approver, $scope, $preview, $binding, 'approved', $at))
        ->toThrow(InvalidArgumentException::class, 'Global autonomy stop');
    expect(DB::table('ai_autonomy_offline_approval_decisions')->count())->toBe(2);
});

it('denies foreign organization approval decisions before mutation', function () {
    [$requester, $approver, $scope, $preview, $binding, $at] = humanApprovalFixture();
    $this->actingAs($approver);
    $foreign = new TenantContext((string) Str::uuid(), $scope->workspaceId, $scope->brandId, $scope->actorId);
    $forged = $preview;
    $forged['tenant'] = $foreign->toArray();
    expect(fn () => app(BoundedAutonomyHumanApprovalRecorder::class)
        ->record($approver, $foreign, $forged, $binding, 'approved', $at))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('ai_autonomy_offline_approval_decisions')->count())->toBe(0);
});
