<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** @return array{user: User, workspace: Workspace} */
function task0041OperatorActor(string $suffix, ?User $user = null): array
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
    $roles->grantPermission($role, PermissionCatalog::CAMPAIGN_READ);
    $roles->assignRole($membership, $role);

    return compact('user', 'workspace');
}

/** @return array{campaign_id: string, snapshot_id: string, snapshot_hash: string} */
function task0041OperatorCampaign(Workspace $workspace, User $user, string $suffix): array
{
    $workspaceId = (string) $workspace->getKey();
    $campaignId = (string) Str::uuid();
    $snapshotId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    $contentVersionId = (string) Str::uuid();
    $targetId = (string) Str::uuid();
    $canonicalReferenceId = (string) Str::uuid();
    $createdAtValue = new \DateTimeImmutable('2026-09-24T12:00:00+00:00');
    $createdAt = '2026-09-24 12:00:00+00:00';

    $target = new CampaignTargetBinding(
        id: $targetId,
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $canonicalReferenceId,
        channel: 'linkedin',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: $createdAtValue,
    );
    $snapshot = CampaignSnapshot::create(
        id: $snapshotId,
        workspaceId: $workspaceId,
        campaignId: $campaignId,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: [
            'mode' => 'fixed_instant',
            'timezone' => 'UTC',
            'at' => '2026-09-24T13:00:00',
        ],
        targets: [$target],
        idempotencyKey: 'operator-snapshot-'.$suffix,
        createdByActorId: (string) $user->getKey(),
        createdAt: $createdAtValue,
    );

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
        'state_version' => 2,
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
        'target_set_hash' => $snapshot->targetSetHash,
        'snapshot_hash' => $snapshot->snapshotHash,
        'idempotency_key' => 'operator-snapshot-'.$suffix,
        'created_by_actor_id' => (string) $user->getKey(),
        'created_at' => $createdAt,
    ]);
    DB::table('campaign_targets')->insert([
        'id' => $targetId,
        'workspace_id' => $workspaceId,
        'snapshot_id' => $snapshotId,
        'kind' => 'contact',
        'canonical_reference_id' => $canonicalReferenceId,
        'channel' => 'linkedin',
        'provider_connection_id' => null,
        'capability_evidence_id' => null,
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'target_hash' => $target->fingerprint(),
        'created_at' => $createdAt,
    ]);

    return [
        'campaign_id' => $campaignId,
        'snapshot_id' => $snapshotId,
        'snapshot_hash' => $snapshot->snapshotHash,
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
            ->where('campaigns.0.snapshot.snapshot_hash', $insideCampaign['snapshot_hash'])
            ->where('campaigns.0.snapshot.channels.0', 'linkedin')
            ->where('campaigns.0.publication.state', 'not_started')
            ->where('campaigns.0.publication.targets.0.state', 'not_started')
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

function task0041GrantApprover(User $user, Workspace $workspace, string $suffix): string
{
    $roles = app(WorkspaceRoleManager::class);
    $membershipId = (string) DB::table('workspace_memberships')
        ->where('workspace_id', $workspace->getKey())
        ->where('user_id', $user->getKey())
        ->value('id');

    $roleKey = 'approver-'.$suffix;
    $roleId = $roles->createRole((string) $workspace->getKey(), $roleKey, 'Approver '.$suffix);
    $roles->grantPermission($roleId, PermissionCatalog::CAMPAIGN_APPROVE);
    $roles->assignRole($membershipId, $roleId);

    return $roleKey;
}

it('preflights approval without mutation and resolves approver authority on the server', function () {
    $actor = task0041OperatorActor('preflight');
    $campaign = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'preflight');
    task0041GrantApprover($actor['user'], $actor['workspace'], 'preflight');

    DB::table('campaigns')->where('id', $campaign['campaign_id'])->update(['status' => 'needs_approval', 'state_version' => 2]);

    $batchId = '11111111-1111-4111-8111-111111111111';
    $this->actingAs($actor['user'])
        ->post('/workspaces/'.$actor['workspace']->getKey().'/publishing/approvals/bulk', [
            'batch_id' => $batchId,
            'operation' => 'approve',
            'confirmed' => false,
            'items' => [[
                'campaign_id' => $campaign['campaign_id'],
                'snapshot_id' => $campaign['snapshot_id'],
                'state_version' => 2,
            ]],
            'role_key' => 'forged-browser-role',
        ])
        ->assertRedirect()
        ->assertSessionHas('publishing_bulk_result', function (array $result): bool {
            return $result['confirmed'] === false
                && $result['counts']['eligible'] === 1
                && $result['counts']['applied'] === 0
                && $result['role_source'] === 'server_resolved_workspace_authority';
        });

    expect(DB::table('campaigns')->where('id', $campaign['campaign_id'])->value('status'))
        ->toBe('needs_approval')
        ->and(DB::table('campaign_approval_decisions')->where('campaign_id', $campaign['campaign_id'])->count())
        ->toBe(0);
});

it('applies only exact-version eligible campaigns and skips stale bulk items', function () {
    $actor = task0041OperatorActor('bulk');
    $first = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'bulk-a');
    $second = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'bulk-b');
    $roleKey = task0041GrantApprover($actor['user'], $actor['workspace'], 'bulk');

    DB::table('campaigns')
        ->whereIn('id', [$first['campaign_id'], $second['campaign_id']])
        ->update(['status' => 'needs_approval', 'state_version' => 2]);

    $payload = [
        'batch_id' => '22222222-2222-4222-8222-222222222222',
        'operation' => 'approve',
        'reason' => 'Reviewed and approved.',
        'role_key' => 'forged-browser-role',
        'items' => [
            [
                'campaign_id' => $first['campaign_id'],
                'snapshot_id' => $first['snapshot_id'],
                'state_version' => 2,
            ],
            [
                'campaign_id' => $second['campaign_id'],
                'snapshot_id' => $second['snapshot_id'],
                'state_version' => 99,
            ],
        ],
    ];

    $this->actingAs($actor['user'])
        ->post('/workspaces/'.$actor['workspace']->getKey().'/publishing/approvals/bulk', $payload + ['confirmed' => false])
        ->assertRedirect()
        ->assertSessionHas('publishing_bulk_result', function (array $result): bool {
            return $result['confirmed'] === false
                && $result['counts']['eligible'] === 1
                && $result['counts']['conflict'] === 1;
        });

    $this->actingAs($actor['user'])
        ->post('/workspaces/'.$actor['workspace']->getKey().'/publishing/approvals/bulk', $payload + ['confirmed' => true])
        ->assertRedirect()
        ->assertSessionHas('publishing_bulk_result', function (array $result): bool {
            return $result['confirmed'] === true
                && $result['counts']['applied'] === 1
                && $result['counts']['conflict'] === 1;
        });

    expect(DB::table('campaigns')->where('id', $first['campaign_id'])->value('status'))
        ->toBe('approved')
        ->and(DB::table('campaigns')->where('id', $second['campaign_id'])->value('status'))
        ->toBe('needs_approval')
        ->and(DB::table('campaign_approval_decisions')->where('campaign_id', $first['campaign_id'])->value('actor_role'))
        ->toBe($roleKey)
        ->and(DB::table('campaign_approval_decisions')->where('campaign_id', $second['campaign_id'])->count())
        ->toBe(0);
});

it('fails closed when bulk approval references a campaign from another workspace', function () {
    $inside = task0041OperatorActor('bulk-inside');
    $foreign = task0041OperatorActor('bulk-foreign');
    $foreignCampaign = task0041OperatorCampaign($foreign['workspace'], $foreign['user'], 'bulk-foreign');
    task0041GrantApprover($inside['user'], $inside['workspace'], 'bulk-inside');

    DB::table('campaigns')->where('id', $foreignCampaign['campaign_id'])->update(['status' => 'needs_approval', 'state_version' => 2]);

    $this->actingAs($inside['user'])
        ->post('/workspaces/'.$inside['workspace']->getKey().'/publishing/approvals/bulk', [
            'batch_id' => '33333333-3333-4333-8333-333333333333',
            'operation' => 'approve',
            'confirmed' => false,
            'items' => [[
                'campaign_id' => $foreignCampaign['campaign_id'],
                'snapshot_id' => $foreignCampaign['snapshot_id'],
                'state_version' => 2,
            ]],
        ])
        ->assertForbidden();

    expect(DB::table('campaign_approval_decisions')->where('campaign_id', $foreignCampaign['campaign_id'])->count())
        ->toBe(0);
});

it('rejects confirmed bulk approval that has no matching server preflight', function () {
    $actor = task0041OperatorActor('confirm-guard');
    $campaign = task0041OperatorCampaign($actor['workspace'], $actor['user'], 'confirm-guard');
    task0041GrantApprover($actor['user'], $actor['workspace'], 'confirm-guard');
    DB::table('campaigns')->where('id', $campaign['campaign_id'])->update(['status' => 'needs_approval', 'state_version' => 2]);

    $this->actingAs($actor['user'])
        ->from('/workspaces/'.$actor['workspace']->getKey().'/publishing')
        ->post('/workspaces/'.$actor['workspace']->getKey().'/publishing/approvals/bulk', [
            'batch_id' => '44444444-4444-4444-8444-444444444444',
            'operation' => 'approve',
            'confirmed' => true,
            'items' => [[
                'campaign_id' => $campaign['campaign_id'],
                'snapshot_id' => $campaign['snapshot_id'],
                'state_version' => 2,
            ]],
        ])
        ->assertRedirect('/workspaces/'.$actor['workspace']->getKey().'/publishing')
        ->assertSessionHasErrors('confirmed');

    expect(DB::table('campaigns')->where('id', $campaign['campaign_id'])->value('status'))
        ->toBe('needs_approval')
        ->and(DB::table('campaign_approval_decisions')->where('campaign_id', $campaign['campaign_id'])->count())
        ->toBe(0);
});
