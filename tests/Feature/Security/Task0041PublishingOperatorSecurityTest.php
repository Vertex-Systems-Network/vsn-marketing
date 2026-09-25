<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** @return array{user: User, workspace: Workspace, role_key: string} */
function task0041OperatorActor(string $suffix, ?User $user = null, array $permissions = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Task0041 '.$suffix,
        'slug' => 'task0041-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Workspace '.$suffix,
        'slug' => 'workspace-'.$suffix,
    ]);
    $user ??= User::query()->create([
        'name' => 'Operator '.$suffix,
        'email' => $suffix.'@task0041.test',
        'password' => Hash::make('secret-pass'),
    ]);

    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $workspace->getKey());
    $role = $roles->createRole((string) $workspace->getKey(), 'operator-'.$suffix, 'Operator '.$suffix);
    foreach (array_unique([PermissionCatalog::CAMPAIGN_READ, ...$permissions]) as $permission) {
        $roles->grantPermission($role, $permission);
    }
    $roles->assignRole($membership, $role);

    return compact('user', 'workspace') + ['role_key' => 'operator-'.$suffix];
}

/** @return array{campaign_id: string, snapshot_id: string} */
function task0041OperatorCampaign(Workspace $workspace, User $user, string $suffix): array
{
    $workspaceId = (string) $workspace->getKey();
    $campaignId = (string) Str::uuid();
    $snapshotId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    $contentVersionId = (string) Str::uuid();
    $targetId = (string) Str::uuid();
    $createdAt = '2026-09-24 12:00:00+00:00';

    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'Operator content '.$suffix,
        'lifecycle' => 'active',
        'created_by_actor_id' => (string) $user->getKey(),
        'audit_provenance' => json_encode(['source' => 'task0041-operator-test'], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
        'updated_at' => null,
    ]);
    DB::table('content_versions')->insert([
        'id' => $contentVersionId,
        'workspace_id' => $workspaceId,
        'document_id' => $documentId,
        'parent_version_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'status' => 'published',
        'canonical_tree' => json_encode(['schema_version' => 1, 'root' => []], JSON_THROW_ON_ERROR),
        'audit_provenance' => json_encode(['source' => 'task0041-operator-test'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'operator-content-'.$suffix,
        'created_by_actor_id' => (string) $user->getKey(),
        'created_at' => $createdAt,
    ]);
    DB::table('campaigns')->insert([
        'id' => $campaignId,
        'workspace_id' => $workspaceId,
        'name' => 'Operator campaign '.$suffix,
        'status' => 'draft',
        'state_version' => 1,
        'idempotency_key' => 'operator-campaign-'.$suffix,
        'created_by_actor_id' => (string) $user->getKey(),
        'created_at' => $createdAt,
        'updated_at' => null,
    ]);
    DB::table('campaign_snapshots')->insert([
        'id' => $snapshotId,
        'workspace_id' => $workspaceId,
        'campaign_id' => $campaignId,
        'parent_snapshot_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'content_version_id' => $contentVersionId,
        'template_version_id' => null,
        'component_version_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'asset_reference_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'capability_evidence_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'brand_reference' => json_encode([], JSON_THROW_ON_ERROR),
        'intended_execution' => json_encode([
            'mode' => 'fixed_instant',
            'timezone' => 'UTC',
            'at' => '2026-09-24T13:00:00',
        ], JSON_THROW_ON_ERROR),
        'target_set_hash' => str_repeat('b', 64),
        'snapshot_hash' => str_repeat('a', 64),
        'idempotency_key' => 'operator-snapshot-'.$suffix,
        'created_by_actor_id' => (string) $user->getKey(),
        'created_at' => $createdAt,
    ]);
    DB::table('campaign_targets')->insert([
        'id' => $targetId,
        'workspace_id' => $workspaceId,
        'snapshot_id' => $snapshotId,
        'kind' => 'contact',
        'canonical_reference_id' => (string) Str::uuid(),
        'channel' => 'linkedin',
        'provider_connection_id' => null,
        'capability_evidence_id' => null,
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'target_hash' => str_repeat('c', 64),
        'created_at' => $createdAt,
    ]);

    return compact('campaignId', 'snapshotId') + [
        'campaign_id' => $campaignId,
        'snapshot_id' => $snapshotId,
    ];
}

it('renders only canonical evidence from the selected authorized workspace', function () {
    $this->withoutVite();
    $inside = task0041OperatorActor('inside');
    $outside = task0041OperatorActor('outside', $inside['user']);
    $insideCampaign = task0041OperatorCampaign($inside['workspace'], $inside['user'], 'inside');
    task0041OperatorCampaign($outside['workspace'], $inside['user'], 'outside');

    $response = $this->actingAs($inside['user'])
        ->get('/workspaces/'.$inside['workspace']->getKey().'/publishing');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('publishing/operator')
            ->where('workspace.id', (string) $inside['workspace']->getKey())
            ->has('campaigns', 1)
            ->where('campaigns.0.id', $insideCampaign['campaign_id'])
            ->where('campaigns.0.snapshot.snapshot_hash', str_repeat('a', 64))
            ->where('campaigns.0.snapshot.channels.0', 'linkedin')
            ->where('campaigns.0.publication.state', 'not_started')
            ->where('campaigns.0.publication.targets.0.state', 'not_started')
            ->where('permissions.can_approve', false)
            ->where('permissions.can_send', false)
            ->where('campaigns.0.approval_actions.approve', false)
            ->where('campaigns.0.bulk_safeguards.retry.affected_count', 0)
            ->where('campaigns.0.bulk_safeguards.retry.blocked_reason', 'permission_denied')
        );

    expect($response->getContent())
        ->not->toContain('provider_connection_id')
        ->not->toContain('capability_evidence_id')
        ->not->toContain('secret_reference')
        ->not->toContain('access_token');
});

it('fails closed when an authenticated user requests a workspace they do not belong to', function () {
    $inside = task0041OperatorActor('member');
    $foreign = task0041OperatorActor('foreign');

    $this->actingAs($inside['user'])
        ->get('/workspaces/'.$foreign['workspace']->getKey().'/publishing')
        ->assertForbidden();
});


it('derives approval role server-side and enforces snapshot plus state-version guards for approve and revoke', function () {
    $actor = task0041OperatorActor(
        'approval-actions',
        null,
        [PermissionCatalog::CAMPAIGN_APPROVE],
    );
    $campaign = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'approval-actions');
    DB::table('campaigns')
        ->where('id', $campaign['campaign_id'])
        ->update(['status' => 'needs_approval', 'state_version' => 2]);

    $this->withoutVite();
    $this->actingAs($actor['user'])
        ->get('/workspaces/'.$actor['workspace']->getKey().'/publishing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_approve', true)
            ->where('campaigns.0.approval_actions.approve', true)
            ->where('campaigns.0.approval_actions.reject', true)
            ->where('campaigns.0.approval_actions.revoke', false)
        );

    $this->actingAs($actor['user'])
        ->post(
            '/workspaces/'.$actor['workspace']->getKey()
                .'/publishing/campaigns/'.$campaign['campaign_id'].'/approval/approve',
            [
                'snapshot_id' => $campaign['snapshot_id'],
                'state_version' => 2,
                'reason' => 'Operator reviewed the immutable snapshot.',
                'role_key' => 'attacker-controlled-role',
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('campaigns')->where('id', $campaign['campaign_id'])->value('status'))
        ->toBe('approved')
        ->and((int) DB::table('campaigns')->where('id', $campaign['campaign_id'])->value('state_version'))
        ->toBe(3)
        ->and(DB::table('campaign_approval_decisions')
            ->where('campaign_id', $campaign['campaign_id'])
            ->where('outcome', 'approved')
            ->value('actor_role'))
        ->toBe($actor['role_key']);

    $this->actingAs($actor['user'])
        ->post(
            '/workspaces/'.$actor['workspace']->getKey()
                .'/publishing/campaigns/'.$campaign['campaign_id'].'/approval/revoke',
            [
                'snapshot_id' => $campaign['snapshot_id'],
                'state_version' => 3,
                'reason' => 'Approval intentionally revoked by the authorized operator.',
            ],
        )
        ->assertRedirect();

    expect(DB::table('campaigns')->where('id', $campaign['campaign_id'])->value('status'))
        ->toBe('needs_approval')
        ->and((int) DB::table('campaigns')->where('id', $campaign['campaign_id'])->value('state_version'))
        ->toBe(4)
        ->and(DB::table('campaign_approval_decisions')
            ->where('campaign_id', $campaign['campaign_id'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('outcome'))
        ->toBe('revoked');
});

it('rejects stale operator approval commands without writing a decision', function () {
    $actor = task0041OperatorActor(
        'stale-approval',
        null,
        [PermissionCatalog::CAMPAIGN_APPROVE],
    );
    $campaign = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'stale-approval');
    DB::table('campaigns')
        ->where('id', $campaign['campaign_id'])
        ->update(['status' => 'needs_approval', 'state_version' => 4]);

    $this->actingAs($actor['user'])
        ->post(
            '/workspaces/'.$actor['workspace']->getKey()
                .'/publishing/campaigns/'.$campaign['campaign_id'].'/approval/approve',
            [
                'snapshot_id' => $campaign['snapshot_id'],
                'state_version' => 3,
            ],
        )
        ->assertSessionHasErrors('approval');

    expect(DB::table('campaign_approval_decisions')
        ->where('campaign_id', $campaign['campaign_id'])
        ->count())
        ->toBe(0);
});

it('denies approval mutations to a read-only workspace operator', function () {
    $actor = task0041OperatorActor('read-only-action');
    $campaign = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'read-only-action');
    DB::table('campaigns')
        ->where('id', $campaign['campaign_id'])
        ->update(['status' => 'needs_approval']);

    $this->actingAs($actor['user'])
        ->post(
            '/workspaces/'.$actor['workspace']->getKey()
                .'/publishing/campaigns/'.$campaign['campaign_id'].'/approval/approve',
            [
                'snapshot_id' => $campaign['snapshot_id'],
                'state_version' => 1,
            ],
        )
        ->assertForbidden();

    expect(DB::table('campaign_approval_decisions')
        ->where('campaign_id', $campaign['campaign_id'])
        ->count())
        ->toBe(0);
});
