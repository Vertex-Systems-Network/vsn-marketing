<?php

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Operator\CampaignApprovalRevocationService;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $fixture */
function task0041RevocationApproverContext(array $fixture): TenantContext
{
    return new TenantContext(
        organizationId: (string) $fixture['organization']->getKey(),
        workspaceId: (string) $fixture['workspace']->getKey(),
        brandId: null,
        actorId: (string) $fixture['approver']->getKey(),
    );
}

/** @param array<string, mixed> $fixture */
function task0041RevocationRoleId(array $fixture): string
{
    return (string) DB::table('workspace_memberships')
        ->join(
            'workspace_membership_roles',
            'workspace_membership_roles.workspace_membership_id',
            '=',
            'workspace_memberships.id',
        )
        ->join(
            'workspace_roles',
            'workspace_roles.id',
            '=',
            'workspace_membership_roles.workspace_role_id',
        )
        ->where('workspace_memberships.workspace_id', $fixture['workspace']->getKey())
        ->where('workspace_memberships.user_id', $fixture['approver']->getKey())
        ->where('workspace_roles.workspace_id', $fixture['workspace']->getKey())
        ->value('workspace_roles.id');
}

it('preflights exact current approval revocation without mutating canonical history', function () {
    $fixture = Task0040PublicationFixture::create('task0041-revoke-preflight');
    $context = task0041RevocationApproverContext($fixture);
    $campaign = $fixture['campaign'];
    $repository = app(DatabaseCampaignRepository::class);
    $before = $repository->approvalDecisions(
        $context->workspaceId,
        $campaign->id,
        $fixture['snapshot']->id,
    );

    $result = app(CampaignApprovalRevocationService::class)->preflight(
        actor: $fixture['approver'],
        context: $context,
        commandId: '11111111-1111-4111-8111-111111111111',
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion,
        reason: 'Material campaign approval change.',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    expect($result['status'])->toBe('eligible')
        ->and($result['confirmation_required'])->toBeTrue()
        ->and($result['provider_side_effect_executed'])->toBeFalse()
        ->and($result['role_source'])->toBe('server_resolved_workspace_authority')
        ->and($result['campaign_id'])->toBe($campaign->id)
        ->and($result['snapshot_id'])->toBe($fixture['snapshot']->id)
        ->and($result['expected_state_version'])->toBe($campaign->stateVersion)
        ->and($result['current_status'])->toBe(CampaignStatus::ScheduledIntent->value)
        ->and(strlen($result['confirmation_hash']))->toBe(64);

    $after = $repository->approvalDecisions(
        $context->workspaceId,
        $campaign->id,
        $fixture['snapshot']->id,
    );

    expect($before)->toHaveCount(1)
        ->and($after)->toHaveCount(1)
        ->and($after[0]->outcome)->toBe(CampaignApprovalOutcome::Approved)
        ->and($repository->findCampaign($context->workspaceId, $campaign->id)?->stateVersion)
        ->toBe($campaign->stateVersion);
});

it('revokes through canonical governance with append-only provenance and idempotent replay', function () {
    $fixture = Task0040PublicationFixture::create('task0041-revoke-apply');
    $context = task0041RevocationApproverContext($fixture);
    $campaign = $fixture['campaign'];
    $service = app(CampaignApprovalRevocationService::class);
    $repository = app(DatabaseCampaignRepository::class);
    $commandId = '22222222-2222-4222-8222-222222222222';
    $reason = 'Approval no longer reflects the current operator decision.';

    $preflight = $service->preflight(
        actor: $fixture['approver'],
        context: $context,
        commandId: $commandId,
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion,
        reason: $reason,
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    $applied = $service->revoke(
        actor: $fixture['approver'],
        context: $context,
        commandId: $commandId,
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion,
        reason: $reason,
        confirmationHash: $preflight['confirmation_hash'],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    $decisions = $repository->approvalDecisions(
        $context->workspaceId,
        $campaign->id,
        $fixture['snapshot']->id,
    );
    $stored = $repository->findCampaign($context->workspaceId, $campaign->id);

    expect($applied['status'])->toBe('applied')
        ->and($applied['provider_side_effect_executed'])->toBeFalse()
        ->and($applied['role_source'])->toBe('server_resolved_workspace_authority')
        ->and($stored?->status)->toBe(CampaignStatus::NeedsApproval)
        ->and($stored?->stateVersion)->toBe($campaign->stateVersion + 1)
        ->and($decisions)->toHaveCount(2)
        ->and($decisions[0]->outcome)->toBe(CampaignApprovalOutcome::Approved)
        ->and($decisions[1]->outcome)->toBe(CampaignApprovalOutcome::Revoked)
        ->and($decisions[1]->supersedesDecisionId)->toBe($decisions[0]->id)
        ->and($decisions[1]->actorId)->toBe((string) $fixture['approver']->getKey())
        ->and($decisions[1]->actorRole)->toBe($preflight['actor_role']);

    $replay = $service->revoke(
        actor: $fixture['approver'],
        context: $context,
        commandId: $commandId,
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion,
        reason: $reason,
        confirmationHash: $preflight['confirmation_hash'],
        at: new DateTimeImmutable('2026-07-15T13:33:00+00:00'),
    );

    expect($replay['status'])->toBe('already_applied')
        ->and($repository->approvalDecisions(
            $context->workspaceId,
            $campaign->id,
            $fixture['snapshot']->id,
        ))->toHaveCount(2)
        ->and($repository->findCampaign($context->workspaceId, $campaign->id)?->stateVersion)
        ->toBe($campaign->stateVersion + 1);
});

it('fails closed on stale campaign version or stale snapshot before revocation', function () {
    $fixture = Task0040PublicationFixture::create('task0041-revoke-stale');
    $context = task0041RevocationApproverContext($fixture);
    $campaign = $fixture['campaign'];
    $service = app(CampaignApprovalRevocationService::class);

    expect(fn () => $service->preflight(
        actor: $fixture['approver'],
        context: $context,
        commandId: '33333333-3333-4333-8333-333333333333',
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion + 1,
        reason: 'Reject stale campaign version.',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'state_version is stale');

    expect(fn () => $service->preflight(
        actor: $fixture['approver'],
        context: $context,
        commandId: '44444444-4444-4444-8444-444444444444',
        campaignId: $campaign->id,
        snapshotId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        expectedStateVersion: $campaign->stateVersion,
        reason: 'Reject stale campaign snapshot.',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'exact latest canonical snapshot');
});

it('rechecks current approver authority after preflight and leaves state unchanged on permission loss', function () {
    $fixture = Task0040PublicationFixture::create('task0041-revoke-role-drift');
    $context = task0041RevocationApproverContext($fixture);
    $campaign = $fixture['campaign'];
    $service = app(CampaignApprovalRevocationService::class);
    $repository = app(DatabaseCampaignRepository::class);
    $commandId = '55555555-5555-4555-8555-555555555555';

    $preflight = $service->preflight(
        actor: $fixture['approver'],
        context: $context,
        commandId: $commandId,
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion,
        reason: 'Current approver authority must still exist.',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    DB::table('workspace_role_permissions')
        ->where('workspace_role_id', task0041RevocationRoleId($fixture))
        ->where('permission', PermissionCatalog::CAMPAIGN_APPROVE)
        ->delete();

    expect(fn () => $service->revoke(
        actor: $fixture['approver'],
        context: $context,
        commandId: $commandId,
        campaignId: $campaign->id,
        snapshotId: $fixture['snapshot']->id,
        expectedStateVersion: $campaign->stateVersion,
        reason: 'Current approver authority must still exist.',
        confirmationHash: $preflight['confirmation_hash'],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(AuthorizationException::class);

    expect($repository->findCampaign($context->workspaceId, $campaign->id)?->status)
        ->toBe(CampaignStatus::ScheduledIntent)
        ->and($repository->approvalDecisions(
            $context->workspaceId,
            $campaign->id,
            $fixture['snapshot']->id,
        ))->toHaveCount(1);
});

it('preserves workspace isolation for approval revocation commands', function () {
    $inside = Task0040PublicationFixture::create('task0041-revoke-inside');
    $outside = Task0040PublicationFixture::create('task0041-revoke-outside');
    $context = task0041RevocationApproverContext($inside);

    expect(fn () => app(CampaignApprovalRevocationService::class)->preflight(
        actor: $inside['approver'],
        context: $context,
        commandId: '66666666-6666-4666-8666-666666666666',
        campaignId: $outside['campaign']->id,
        snapshotId: $outside['snapshot']->id,
        expectedStateVersion: $outside['campaign']->stateVersion,
        reason: 'Cross-workspace revocation must be rejected.',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    ))->toThrow(AuthorizationException::class);

    expect(app(DatabaseCampaignRepository::class)->findCampaign(
        (string) $outside['workspace']->getKey(),
        $outside['campaign']->id,
    )?->status)->toBe(CampaignStatus::ScheduledIntent);
});
