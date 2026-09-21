<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Publishing\Application\Governance\CampaignGovernanceService;
use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalInvalidReason;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/** @return array{organization: Organization, workspace: Workspace, user: User, context: TenantContext} */
function task0038GovernanceActor(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Task0038 '.$suffix,
        'slug' => 'task0038-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Task0038 '.$suffix,
        'slug' => 'task0038-'.$suffix,
    ]);
    $user = User::query()->create([
        'name' => 'Task0038 '.$suffix,
        'email' => $suffix.'@task0038.test',
        'password' => Hash::make('secret-pass'),
    ]);

    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'user' => $user,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: null,
            actorId: (string) $user->getKey(),
        ),
    ];
}

function task0038GovernanceGrant(
    User $user,
    string $workspaceId,
    string $roleKey,
    array $permissions,
): string {
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, $workspaceId);
    $roleId = $roles->createRole($workspaceId, $roleKey, $roleKey);

    foreach ($permissions as $permission) {
        $roles->grantPermission($roleId, $permission);
    }

    $roles->assignRole($membership, $roleId);

    return $roleId;
}

/** @return array{content_version_id: string, contact_id: string} */
function task0038GovernanceCanonicalInputs(string $workspaceId, string $suffix): array
{
    $documentId = (string) Str::uuid();
    $versionId = (string) Str::uuid();
    $contactId = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-09-22T00:00:00+00:00');

    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'Governance content '.$suffix,
        'lifecycle' => 'active',
        'created_by_actor_id' => 'task0038-author',
        'audit_provenance' => json_encode(['source' => 'task0038-governance-test'], JSON_THROW_ON_ERROR),
        'created_at' => $at,
        'updated_at' => null,
    ]);
    DB::table('content_versions')->insert([
        'id' => $versionId,
        'workspace_id' => $workspaceId,
        'document_id' => $documentId,
        'parent_version_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'status' => 'published',
        'canonical_tree' => json_encode(['schema_version' => 1, 'root' => []], JSON_THROW_ON_ERROR),
        'audit_provenance' => json_encode(['source' => 'task0038-governance-test'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'content-'.$suffix,
        'created_by_actor_id' => 'task0038-author',
        'created_at' => $at,
    ]);
    DB::table('contacts')->insert([
        'id' => $contactId,
        'workspace_id' => $workspaceId,
        'brand_id' => null,
        'company_id' => null,
        'first_name' => 'Governance',
        'last_name' => $suffix,
        'display_name' => 'Governance '.$suffix,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return ['content_version_id' => $versionId, 'contact_id' => $contactId];
}

/** @return array{campaign: Campaign, snapshot: CampaignSnapshot} */
function task0038GovernanceCampaign(
    string $workspaceId,
    string $contentVersionId,
    CampaignTargetBinding $target,
    array $capabilityIds = [],
): array {
    $repository = app(DatabaseCampaignRepository::class);
    $createdAt = new DateTimeImmutable('2026-09-22T00:05:00+00:00');
    $campaign = Campaign::draft(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Governed campaign',
        idempotencyKey: 'campaign-'.Str::uuid(),
        createdByActorId: 'task0038-author',
        createdAt: $createdAt,
    );
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created(
            $campaign,
            (string) Str::uuid(),
            'task0038-author',
            'Created for governance test.',
            [],
            'event-create-'.Str::uuid(),
            $createdAt,
        ),
    );

    $reviewAt = new DateTimeImmutable('2026-09-22T00:06:00+00:00');
    $review = $campaign->transitionTo(CampaignStatus::Review, $reviewAt);
    $repository->transitionCampaign(
        $review,
        $campaign->stateVersion,
        CampaignEvent::transitioned(
            $campaign,
            $review,
            (string) Str::uuid(),
            'task0038-author',
            'Ready for review.',
            [],
            'event-review-'.Str::uuid(),
            $reviewAt,
        ),
    );

    $snapshot = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaign->id,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: $capabilityIds,
        brandReference: [],
        intendedExecution: ['mode' => 'intent_only'],
        targets: [$target],
        idempotencyKey: 'snapshot-'.Str::uuid(),
        createdByActorId: 'task0038-author',
        createdAt: new DateTimeImmutable('2026-09-22T00:07:00+00:00'),
    );
    $repository->appendSnapshot(
        $snapshot,
        CampaignEvent::snapshotCreated(
            $snapshot,
            (string) Str::uuid(),
            'task0038-author',
            'Approval candidate.',
            [],
            'event-snapshot-'.Str::uuid(),
            new DateTimeImmutable('2026-09-22T00:07:00+00:00'),
        ),
    );

    return ['campaign' => $review, 'snapshot' => $snapshot];
}

it('fails ready closed after approver authority revocation and appends an explicit revocation decision during reconciliation', function () {
    $submitter = task0038GovernanceActor('submitter');
    $workspaceId = (string) $submitter['workspace']->getKey();

    $approver = User::query()->create([
        'name' => 'Original approver',
        'email' => 'original-approver@task0038.test',
        'password' => Hash::make('secret-pass'),
    ]);
    $reconciler = User::query()->create([
        'name' => 'Current approver',
        'email' => 'current-approver@task0038.test',
        'password' => Hash::make('secret-pass'),
    ]);

    task0038GovernanceGrant(
        $submitter['user'],
        $workspaceId,
        'campaign-editor',
        [PermissionCatalog::CAMPAIGN_CREATE, PermissionCatalog::CAMPAIGN_SEND],
    );
    $originalRoleId = task0038GovernanceGrant(
        $approver,
        $workspaceId,
        'campaign-approver-original',
        [PermissionCatalog::CAMPAIGN_APPROVE],
    );
    task0038GovernanceGrant(
        $reconciler,
        $workspaceId,
        'campaign-approver-current',
        [PermissionCatalog::CAMPAIGN_APPROVE],
    );

    $inputs = task0038GovernanceCanonicalInputs($workspaceId, 'authority');
    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $inputs['contact_id'],
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-22T00:07:00+00:00'),
    );
    $fixture = task0038GovernanceCampaign(
        $workspaceId,
        $inputs['content_version_id'],
        $target,
    );

    $service = app(CampaignGovernanceService::class);
    $needsApproval = $service->requestApproval(
        $submitter['user'],
        $submitter['context'],
        $fixture['campaign']->id,
        $fixture['snapshot']->id,
        (string) Str::uuid(),
        'request-approval',
        'Request exact snapshot approval.',
        new DateTimeImmutable('2026-09-22T00:08:00+00:00'),
    );

    $approverContext = new TenantContext(
        organizationId: (string) $submitter['organization']->getKey(),
        workspaceId: $workspaceId,
        brandId: null,
        actorId: (string) $approver->getKey(),
    );
    $approved = $service->approve(
        $approver,
        $approverContext,
        $needsApproval->id,
        $fixture['snapshot']->id,
        'campaign-approver-original',
        (string) Str::uuid(),
        'approval-decision',
        (string) Str::uuid(),
        'approval-event',
        (string) Str::uuid(),
        'approval-transition',
        'Exact immutable snapshot approved.',
        new DateTimeImmutable('2026-09-22T01:00:00+00:00'),
        new DateTimeImmutable('2026-09-22T00:09:00+00:00'),
    );

    expect($approved->status)->toBe(CampaignStatus::Approved);

    DB::table('workspace_role_permissions')
        ->where('workspace_role_id', $originalRoleId)
        ->where('permission', PermissionCatalog::CAMPAIGN_APPROVE)
        ->delete();

    expect(fn () => $service->markReady(
        $submitter['user'],
        $submitter['context'],
        $approved->id,
        (string) Str::uuid(),
        'mark-ready-after-revocation',
        null,
        new DateTimeImmutable('2026-09-22T00:10:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'approver_authorization_revoked');

    $reconcilerContext = new TenantContext(
        organizationId: (string) $submitter['organization']->getKey(),
        workspaceId: $workspaceId,
        brandId: null,
        actorId: (string) $reconciler->getKey(),
    );
    $evaluation = $service->reconcileApproval(
        $reconciler,
        $reconcilerContext,
        $approved->id,
        'campaign-approver-current',
        (string) Str::uuid(),
        'reconcile-revocation-decision',
        (string) Str::uuid(),
        'reconcile-revocation-event',
        (string) Str::uuid(),
        'reconcile-transition-event',
        new DateTimeImmutable('2026-09-22T00:11:00+00:00'),
    );

    $repository = app(DatabaseCampaignRepository::class);
    $decisions = $repository->approvalDecisions($workspaceId, $approved->id, $fixture['snapshot']->id);

    expect($evaluation->valid)->toBeFalse()
        ->and($evaluation->reason)->toBe(CampaignApprovalInvalidReason::ApproverAuthorizationRevoked)
        ->and($repository->findCampaign($workspaceId, $approved->id)?->status)->toBe(CampaignStatus::NeedsApproval)
        ->and($decisions)->toHaveCount(2)
        ->and($decisions[0]->outcome)->toBe(CampaignApprovalOutcome::Approved)
        ->and($decisions[1]->outcome)->toBe(CampaignApprovalOutcome::Revoked)
        ->and($decisions[1]->supersedesDecisionId)->toBe($decisions[0]->id);
});

it('rejects approval requests when provider capability evidence is not effective yet', function () {
    $actor = task0038GovernanceActor('provider-future');
    $workspaceId = (string) $actor['workspace']->getKey();
    task0038GovernanceGrant(
        $actor['user'],
        $workspaceId,
        'campaign-editor-provider',
        [PermissionCatalog::CAMPAIGN_CREATE],
    );

    $inputs = task0038GovernanceCanonicalInputs($workspaceId, 'provider-future');
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $capabilityId = (string) Str::uuid();
    $now = new DateTimeImmutable('2026-09-22T00:10:00+00:00');

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $workspaceId,
        'provider_key' => 'task0038-provider',
        'display_name' => 'Task0038 Provider',
        'category' => 'social',
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/provider',
        'source_version' => 'v1',
        'observed_at' => new DateTimeImmutable('2026-09-22T00:00:00+00:00'),
        'fresh_until' => new DateTimeImmutable('2026-10-22T00:00:00+00:00'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_connections')->insert([
        'id' => $connectionId,
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'name' => 'Primary',
        'readiness_status' => 'ready',
        'auth_family' => 'oauth2',
        'secret_reference' => 'vault://task0038/provider',
        'requested_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
        'granted_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
        'roles' => json_encode(['publisher'], JSON_THROW_ON_ERROR),
        'access_tier' => null,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'provider_review_status' => 'approved',
        'token_expires_at' => new DateTimeImmutable('2026-10-22T00:00:00+00:00'),
        'refresh_supported' => true,
        'last_rotated_at' => null,
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/provider/connection',
        'source_version' => 'v1',
        'observed_at' => new DateTimeImmutable('2026-09-22T00:00:00+00:00'),
        'fresh_until' => new DateTimeImmutable('2026-10-22T00:00:00+00:00'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_capabilities')->insert([
        'id' => $capabilityId,
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'operation' => 'publish.intent',
        'support_status' => 'supported',
        'required_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
        'required_roles' => json_encode(['publisher'], JSON_THROW_ON_ERROR),
        'constraints' => json_encode([], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/provider/capability',
        'source_version' => 'v1',
        'observed_at' => new DateTimeImmutable('2026-09-22T00:30:00+00:00'),
        'fresh_until' => new DateTimeImmutable('2026-10-22T00:30:00+00:00'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::ProviderConnection,
        canonicalReferenceId: $connectionId,
        channel: 'social',
        providerConnectionId: $connectionId,
        capabilityEvidenceId: $capabilityId,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-22T00:07:00+00:00'),
    );
    $fixture = task0038GovernanceCampaign(
        $workspaceId,
        $inputs['content_version_id'],
        $target,
        [$capabilityId],
    );

    expect(fn () => app(CampaignGovernanceService::class)->requestApproval(
        $actor['user'],
        $actor['context'],
        $fixture['campaign']->id,
        $fixture['snapshot']->id,
        (string) Str::uuid(),
        'future-capability-request',
        null,
        new DateTimeImmutable('2026-09-22T00:10:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'capability_not_effective');

    expect(app(DatabaseCampaignRepository::class)
        ->findCampaign($workspaceId, $fixture['campaign']->id)?->status)
        ->toBe(CampaignStatus::Review);
});

it('records immutable target-change provenance, invalidates stale approval, and requires fresh approval before completion', function () {
    $editor = task0038GovernanceActor('revision-history-editor');
    $workspaceId = (string) $editor['workspace']->getKey();
    task0038GovernanceGrant(
        $editor['user'],
        $workspaceId,
        'campaign-revision-editor',
        [PermissionCatalog::CAMPAIGN_CREATE, PermissionCatalog::CAMPAIGN_SEND],
    );

    $approver = User::query()->create([
        'name' => 'Revision approver',
        'email' => 'revision-approver@task0038.test',
        'password' => Hash::make('secret-pass'),
    ]);
    task0038GovernanceGrant(
        $approver,
        $workspaceId,
        'campaign-revision-approver',
        [PermissionCatalog::CAMPAIGN_APPROVE],
    );
    $approverContext = new TenantContext(
        organizationId: (string) $editor['organization']->getKey(),
        workspaceId: $workspaceId,
        brandId: null,
        actorId: (string) $approver->getKey(),
    );

    $inputs = task0038GovernanceCanonicalInputs($workspaceId, 'revision-history');
    $secondContactId = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-09-22T02:00:00+00:00');
    DB::table('contacts')->insert([
        'id' => $secondContactId,
        'workspace_id' => $workspaceId,
        'brand_id' => null,
        'company_id' => null,
        'first_name' => 'Revision',
        'last_name' => 'Target',
        'display_name' => 'Revision Target',
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $firstTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $inputs['contact_id'],
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: ['selection' => 'initial'],
        createdAt: new DateTimeImmutable('2026-09-22T02:01:00+00:00'),
    );
    $fixture = task0038GovernanceCampaign(
        $workspaceId,
        $inputs['content_version_id'],
        $firstTarget,
    );

    $service = app(CampaignGovernanceService::class);
    $needsApproval = $service->requestApproval(
        $editor['user'],
        $editor['context'],
        $fixture['campaign']->id,
        $fixture['snapshot']->id,
        (string) Str::uuid(),
        'revision-request-v1',
        'Approve first target set.',
        new DateTimeImmutable('2026-09-22T02:02:00+00:00'),
    );
    $approvedV1 = $service->approve(
        $approver,
        $approverContext,
        $needsApproval->id,
        $fixture['snapshot']->id,
        'campaign-revision-approver',
        (string) Str::uuid(),
        'revision-approval-v1',
        (string) Str::uuid(),
        'revision-approval-event-v1',
        (string) Str::uuid(),
        'revision-approved-transition-v1',
        'Approved initial immutable target set.',
        new DateTimeImmutable('2026-09-22T04:00:00+00:00'),
        new DateTimeImmutable('2026-09-22T02:03:00+00:00'),
    );

    $secondTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $secondContactId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: ['selection' => 'replacement'],
        createdAt: new DateTimeImmutable('2026-09-22T02:04:00+00:00'),
    );
    $revision = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $approvedV1->id,
        parentSnapshotId: $fixture['snapshot']->id,
        versionNumber: 2,
        contentVersionId: $inputs['content_version_id'],
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'intent_only'],
        targets: [$secondTarget],
        idempotencyKey: 'revision-snapshot-v2',
        createdByActorId: (string) $editor['user']->getKey(),
        createdAt: new DateTimeImmutable('2026-09-22T02:04:00+00:00'),
    );

    $persisted = $service->appendMaterialRevision(
        $editor['user'],
        $editor['context'],
        $revision,
        (string) Str::uuid(),
        'revision-snapshot-event-v2',
        (string) Str::uuid(),
        'revision-invalidation-v2',
        'Changed the canonical target set.',
        new DateTimeImmutable('2026-09-22T02:04:00+00:00'),
    );

    $repository = app(DatabaseCampaignRepository::class);
    $history = $repository->history($workspaceId, $approvedV1->id);
    $revisionEvent = collect($history)->first(
        static fn (CampaignEvent $event): bool => $event->idempotencyKey === 'revision-snapshot-event-v2',
    );

    expect($persisted->parentSnapshotId)->toBe($fixture['snapshot']->id)
        ->and($persisted->targetSetHash)->not->toBe($fixture['snapshot']->targetSetHash)
        ->and($repository->findCampaign($workspaceId, $approvedV1->id)?->status)->toBe(CampaignStatus::NeedsApproval)
        ->and($revisionEvent)->not->toBeNull()
        ->and($revisionEvent->evidence['revision_kind'] ?? null)->toBe('material')
        ->and($revisionEvent->evidence['previous_target_set_hash'] ?? null)->toBe($fixture['snapshot']->targetSetHash)
        ->and($revisionEvent->evidence['target_set_hash'] ?? null)->toBe($persisted->targetSetHash)
        ->and($revisionEvent->evidence['target_set_changed'] ?? null)->toBeTrue()
        ->and($repository->approvalDecisions($workspaceId, $approvedV1->id, $fixture['snapshot']->id))->toHaveCount(1)
        ->and($repository->approvalDecisions($workspaceId, $approvedV1->id, $persisted->id))->toHaveCount(0);

    expect(fn () => $service->complete(
        $editor['user'],
        $editor['context'],
        $approvedV1->id,
        (string) Str::uuid(),
        'revision-premature-complete',
        'Must not complete stale approval.',
        new DateTimeImmutable('2026-09-22T02:05:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'requires ready or scheduled_intent');

    $approvedV2 = $service->approve(
        $approver,
        $approverContext,
        $approvedV1->id,
        $persisted->id,
        'campaign-revision-approver',
        (string) Str::uuid(),
        'revision-approval-v2',
        (string) Str::uuid(),
        'revision-approval-event-v2',
        (string) Str::uuid(),
        'revision-approved-transition-v2',
        'Approved revised immutable target set.',
        new DateTimeImmutable('2026-09-22T04:00:00+00:00'),
        new DateTimeImmutable('2026-09-22T02:06:00+00:00'),
    );
    $ready = $service->markReady(
        $editor['user'],
        $editor['context'],
        $approvedV2->id,
        (string) Str::uuid(),
        'revision-ready-v2',
        'Ready after fresh approval.',
        new DateTimeImmutable('2026-09-22T02:07:00+00:00'),
    );
    $completed = $service->complete(
        $editor['user'],
        $editor['context'],
        $ready->id,
        (string) Str::uuid(),
        'revision-complete-v2',
        'Canonical work completed; no provider execution is performed here.',
        new DateTimeImmutable('2026-09-22T02:08:00+00:00'),
    );

    $completion = collect($repository->history($workspaceId, $completed->id))->first(
        static fn (CampaignEvent $event): bool => $event->idempotencyKey === 'revision-complete-v2',
    );

    expect($completed->status)->toBe(CampaignStatus::Completed)
        ->and($completion)->not->toBeNull()
        ->and($completion->evidence['decision'] ?? null)->toBe('completed')
        ->and($completion->evidence['snapshot_id'] ?? null)->toBe($persisted->id)
        ->and($completion->evidence['target_set_hash'] ?? null)->toBe($persisted->targetSetHash)
        ->and($completion->evidence['approval_id'] ?? null)->not->toBeNull();
});

it('records cancellation provenance and keeps terminal campaign history append-only', function () {
    $editor = task0038GovernanceActor('cancel-history-editor');
    $workspaceId = (string) $editor['workspace']->getKey();
    task0038GovernanceGrant(
        $editor['user'],
        $workspaceId,
        'campaign-cancel-editor',
        [PermissionCatalog::CAMPAIGN_CREATE],
    );

    $inputs = task0038GovernanceCanonicalInputs($workspaceId, 'cancel-history');
    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $inputs['contact_id'],
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-22T03:00:00+00:00'),
    );
    $fixture = task0038GovernanceCampaign($workspaceId, $inputs['content_version_id'], $target);
    $service = app(CampaignGovernanceService::class);

    $cancelled = $service->cancel(
        $editor['user'],
        $editor['context'],
        $fixture['campaign']->id,
        (string) Str::uuid(),
        'cancel-history-event',
        'Operator cancelled before approval.',
        new DateTimeImmutable('2026-09-22T03:01:00+00:00'),
    );

    $repository = app(DatabaseCampaignRepository::class);
    $cancelEvent = collect($repository->history($workspaceId, $cancelled->id))->first(
        static fn (CampaignEvent $event): bool => $event->idempotencyKey === 'cancel-history-event',
    );

    expect($cancelled->status)->toBe(CampaignStatus::Cancelled)
        ->and($cancelEvent)->not->toBeNull()
        ->and($cancelEvent->evidence['decision'] ?? null)->toBe('cancelled')
        ->and($cancelEvent->evidence['snapshot_id'] ?? null)->toBe($fixture['snapshot']->id)
        ->and($cancelEvent->evidence['snapshot_hash'] ?? null)->toBe($fixture['snapshot']->snapshotHash)
        ->and($cancelEvent->evidence['target_set_hash'] ?? null)->toBe($fixture['snapshot']->targetSetHash);

    expect(fn () => $service->appendMaterialRevision(
        $editor['user'],
        $editor['context'],
        CampaignSnapshot::create(
            id: (string) Str::uuid(),
            workspaceId: $workspaceId,
            campaignId: $cancelled->id,
            parentSnapshotId: $fixture['snapshot']->id,
            versionNumber: 2,
            contentVersionId: $inputs['content_version_id'],
            templateVersionId: null,
            componentVersionIds: [],
            assetReferenceIds: [],
            capabilityEvidenceIds: [],
            brandReference: [],
            intendedExecution: ['mode' => 'intent_only'],
            targets: [$target],
            idempotencyKey: 'cancelled-revision',
            createdByActorId: (string) $editor['user']->getKey(),
            createdAt: new DateTimeImmutable('2026-09-22T03:02:00+00:00'),
        ),
        (string) Str::uuid(),
        'cancelled-revision-event',
        (string) Str::uuid(),
        'cancelled-invalidation-event',
        'Should fail closed.',
        new DateTimeImmutable('2026-09-22T03:02:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Terminal campaigns cannot create');
});
