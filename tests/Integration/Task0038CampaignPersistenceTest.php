<?php

use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) === false) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0038 PostgreSQL persistence tests.');
    }
});

function task0038PersistenceWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0038 '.$suffix,
        'slug' => 'task0038-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0038 Workspace '.$suffix,
        'slug' => 'task0038-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

function task0038PersistenceContentVersion(string $workspaceId, string $suffix): string
{
    $documentId = (string) Str::uuid();
    $versionId = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-09-21T16:00:00+00:00');

    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0038 content '.$suffix,
        'lifecycle' => 'active',
        'created_by_actor_id' => 'task0038-author',
        'audit_provenance' => json_encode(['source' => 'task0038-test'], JSON_THROW_ON_ERROR),
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
        'audit_provenance' => json_encode(['source' => 'task0038-test'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'task0038-content-'.$suffix,
        'created_by_actor_id' => 'task0038-author',
        'created_at' => $at,
    ]);

    return $versionId;
}

function task0038PersistenceContact(string $workspaceId, string $suffix): string
{
    $contactId = (string) Str::uuid();
    $now = now();

    DB::table('contacts')->insert([
        'id' => $contactId,
        'workspace_id' => $workspaceId,
        'brand_id' => null,
        'company_id' => null,
        'first_name' => 'Task',
        'last_name' => '0038 '.$suffix,
        'display_name' => 'Task0038 '.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $contactId;
}

function task0038PersistenceContactIdentity(
    string $workspaceId,
    string $contactId,
    string $suffix,
): string {
    $identityId = (string) Str::uuid();
    $now = now();

    DB::table('contact_identities')->insert([
        'id' => $identityId,
        'workspace_id' => $workspaceId,
        'contact_id' => $contactId,
        'type' => 'email',
        'value' => $suffix.'@task0038.test',
        'normalized_value' => $suffix.'@task0038.test',
        'provider' => null,
        'provider_reference' => null,
        'verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $identityId;
}

function task0038PersistenceList(
    string $workspaceId,
    array $contactIds,
    string $suffix,
): string {
    $listId = (string) Str::uuid();
    $now = now();

    DB::table('contact_lists')->insert([
        'id' => $listId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0038 list '.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ($contactIds as $contactId) {
        DB::table('contact_list_memberships')->insert([
            'workspace_id' => $workspaceId,
            'list_id' => $listId,
            'contact_id' => $contactId,
            'created_at' => $now,
        ]);
    }

    return $listId;
}

function task0038PersistenceTag(
    string $workspaceId,
    array $contactIds,
    string $suffix,
): string {
    $tagId = (string) Str::uuid();
    $now = now();

    DB::table('tags')->insert([
        'id' => $tagId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0038 tag '.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ($contactIds as $contactId) {
        DB::table('contact_tag_assignments')->insert([
            'workspace_id' => $workspaceId,
            'tag_id' => $tagId,
            'contact_id' => $contactId,
            'created_at' => $now,
        ]);
    }

    return $tagId;
}

function task0038PersistenceCampaign(string $workspaceId, string $suffix, DateTimeImmutable $at): Campaign
{
    return Campaign::draft(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Task0038 '.$suffix,
        idempotencyKey: 'campaign-'.$suffix,
        createdByActorId: 'task0038-author',
        createdAt: $at,
    );
}

it('persists replay-safe campaign lifecycle snapshots approvals and append-only history on PostgreSQL', function () {
    $workspaceId = task0038PersistenceWorkspace('foundation');
    $contentVersionId = task0038PersistenceContentVersion($workspaceId, 'foundation');
    $contactId = task0038PersistenceContact($workspaceId, 'foundation');
    $repository = app(DatabaseCampaignRepository::class);
    $at = new DateTimeImmutable('2026-09-21T16:10:00+00:00');

    $campaign = task0038PersistenceCampaign($workspaceId, 'foundation', $at);
    $createdEvent = CampaignEvent::created(
        campaign: $campaign,
        id: (string) Str::uuid(),
        actorId: 'task0038-author',
        reason: 'Campaign created',
        evidence: ['source' => 'integration'],
        idempotencyKey: 'event-campaign-created',
        occurredAt: $at,
    );

    expect($repository->createCampaign($campaign, $createdEvent)->id)->toBe($campaign->id)
        ->and($repository->createCampaign($campaign, $createdEvent)->id)->toBe($campaign->id)
        ->and(DB::table('campaigns')->where('id', $campaign->id)->count())->toBe(1)
        ->and(DB::table('campaign_events')->where('idempotency_key', 'event-campaign-created')->count())->toBe(1);

    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $contactId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: ['selection' => 'exact-contact'],
        createdAt: new DateTimeImmutable('2026-09-21T16:11:00+00:00'),
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
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'fixed_instant', 'timezone' => 'UTC', 'at' => '2026-09-22T09:00:00Z'],
        targets: [$target],
        idempotencyKey: 'snapshot-foundation-v1',
        createdByActorId: 'task0038-author',
        createdAt: new DateTimeImmutable('2026-09-21T16:11:00+00:00'),
    );
    $snapshotEvent = CampaignEvent::snapshotCreated(
        snapshot: $snapshot,
        id: (string) Str::uuid(),
        actorId: 'task0038-author',
        reason: 'Freeze approval candidate',
        evidence: ['snapshot_hash' => $snapshot->snapshotHash],
        idempotencyKey: 'event-snapshot-v1',
        occurredAt: new DateTimeImmutable('2026-09-21T16:11:00+00:00'),
    );

    $persistedSnapshot = $repository->appendSnapshot($snapshot, $snapshotEvent);
    $replayedSnapshot = $repository->appendSnapshot($snapshot, $snapshotEvent);

    expect($persistedSnapshot->snapshotHash)->toBe($snapshot->snapshotHash)
        ->and($replayedSnapshot->id)->toBe($snapshot->id)
        ->and(DB::table('campaign_snapshots')->where('id', $snapshot->id)->count())->toBe(1)
        ->and(DB::table('campaign_targets')->where('snapshot_id', $snapshot->id)->count())->toBe(1);

    $review = $campaign->transitionTo(CampaignStatus::Review, new DateTimeImmutable('2026-09-21T16:12:00+00:00'));
    $reviewEvent = CampaignEvent::transitioned(
        $campaign,
        $review,
        (string) Str::uuid(),
        'task0038-author',
        'Ready for review',
        [],
        'event-review',
        new DateTimeImmutable('2026-09-21T16:12:00+00:00'),
    );
    $repository->transitionCampaign($review, 1, $reviewEvent);

    $needsApproval = $review->transitionTo(CampaignStatus::NeedsApproval, new DateTimeImmutable('2026-09-21T16:13:00+00:00'));
    $needsApprovalEvent = CampaignEvent::transitioned(
        $review,
        $needsApproval,
        (string) Str::uuid(),
        'reviewer-1',
        'Approval required',
        [],
        'event-needs-approval',
        new DateTimeImmutable('2026-09-21T16:13:00+00:00'),
    );
    $repository->transitionCampaign($needsApproval, 2, $needsApprovalEvent);

    $decision = new CampaignApprovalDecision(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaign->id,
        snapshotId: $snapshot->id,
        targetSetHash: $snapshot->targetSetHash,
        outcome: CampaignApprovalOutcome::Approved,
        actorId: 'approver-1',
        actorRole: 'campaign-approver',
        reason: 'Exact snapshot approved',
        capabilityEvidenceIds: [],
        supersedesDecisionId: null,
        expiresAt: new DateTimeImmutable('2026-09-22T16:14:00+00:00'),
        idempotencyKey: 'approval-foundation-v1',
        occurredAt: new DateTimeImmutable('2026-09-21T16:14:00+00:00'),
    );
    $approvalEvent = CampaignEvent::approvalRecorded(
        decision: $decision,
        id: (string) Str::uuid(),
        evidence: ['snapshot_hash' => $snapshot->snapshotHash, 'target_set_hash' => $snapshot->targetSetHash],
        idempotencyKey: 'event-approval-v1',
    );

    expect($repository->appendApproval($decision, $approvalEvent)->id)->toBe($decision->id)
        ->and($repository->appendApproval($decision, $approvalEvent)->id)->toBe($decision->id)
        ->and(DB::table('campaign_approval_decisions')->where('id', $decision->id)->count())->toBe(1);

    $approved = $needsApproval->transitionTo(CampaignStatus::Approved, new DateTimeImmutable('2026-09-21T16:15:00+00:00'));
    $approvedEvent = CampaignEvent::transitioned(
        $needsApproval,
        $approved,
        (string) Str::uuid(),
        'approver-1',
        'Approval recorded',
        ['approval_id' => $decision->id],
        'event-approved',
        new DateTimeImmutable('2026-09-21T16:15:00+00:00'),
    );
    $repository->transitionCampaign($approved, 3, $approvedEvent);

    expect($repository->findCampaign($workspaceId, $campaign->id)?->status)->toBe(CampaignStatus::Approved)
        ->and($repository->findSnapshot($workspaceId, $snapshot->id)?->targetSetHash)->toBe($snapshot->targetSetHash)
        ->and($repository->history($workspaceId, $campaign->id))->toHaveCount(6)
        ->and(array_map(
            static fn (CampaignEvent $event): string => $event->type,
            $repository->history($workspaceId, $campaign->id),
        ))->toBe([
            CampaignEvent::CREATED,
            CampaignEvent::SNAPSHOT_CREATED,
            CampaignEvent::LIFECYCLE_TRANSITIONED,
            CampaignEvent::LIFECYCLE_TRANSITIONED,
            CampaignEvent::APPROVAL_RECORDED,
            CampaignEvent::LIFECYCLE_TRANSITIONED,
        ]);

    $stale = $needsApproval->transitionTo(CampaignStatus::Cancelled, new DateTimeImmutable('2026-09-21T16:16:00+00:00'));
    $staleEvent = CampaignEvent::transitioned(
        $needsApproval,
        $stale,
        (string) Str::uuid(),
        'reviewer-1',
        'Stale cancellation',
        [],
        'event-stale-cancel',
        new DateTimeImmutable('2026-09-21T16:16:00+00:00'),
    );

    expect(fn () => $repository->transitionCampaign($stale, 3, $staleEvent))
        ->toThrow(InvalidArgumentException::class, 'optimistic concurrency conflict');

    expect(fn () => DB::connection()->transaction(
        fn () => DB::table('campaign_snapshots')
            ->where('id', $snapshot->id)
            ->update(['snapshot_hash' => str_repeat('a', 64)]),
    ))->toThrow(QueryException::class)
        ->and(fn () => DB::connection()->transaction(
            fn () => DB::table('campaign_approval_decisions')->where('id', $decision->id)->delete(),
        ))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::connection()->transaction(
            fn () => DB::table('campaign_events')->where('campaign_id', $campaign->id)->delete(),
        ))
        ->toThrow(QueryException::class);

    $migration = require database_path('migrations/2026_09_21_000001_create_campaign_foundation_tables.php');
    $migration->up();

    expect(DB::table('campaigns')->where('id', $campaign->id)->count())->toBe(1)
        ->and(DB::table('campaign_snapshots')->where('id', $snapshot->id)->count())->toBe(1);
});

it('fails closed on foreign-workspace targets and rolls back partial snapshot persistence', function () {
    $inside = task0038PersistenceWorkspace('inside');
    $outside = task0038PersistenceWorkspace('outside');
    $contentVersionId = task0038PersistenceContentVersion($inside, 'isolation');
    $foreignContactId = task0038PersistenceContact($outside, 'foreign');
    $repository = app(DatabaseCampaignRepository::class);
    $at = new DateTimeImmutable('2026-09-21T17:00:00+00:00');

    $campaign = task0038PersistenceCampaign($inside, 'isolation', $at);
    $createdEvent = CampaignEvent::created(
        $campaign,
        (string) Str::uuid(),
        'task0038-author',
        null,
        [],
        'event-isolation-create',
        $at,
    );
    $repository->createCampaign($campaign, $createdEvent);

    $foreignTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $foreignContactId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-21T17:01:00+00:00'),
    );
    $snapshot = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        campaignId: $campaign->id,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$foreignTarget],
        idempotencyKey: 'snapshot-isolation-v1',
        createdByActorId: 'task0038-author',
        createdAt: new DateTimeImmutable('2026-09-21T17:01:00+00:00'),
    );
    $event = CampaignEvent::snapshotCreated(
        $snapshot,
        (string) Str::uuid(),
        'task0038-author',
        null,
        [],
        'event-isolation-snapshot',
        new DateTimeImmutable('2026-09-21T17:01:00+00:00'),
    );

    expect(fn () => $repository->appendSnapshot($snapshot, $event))
        ->toThrow(AuthorizationException::class, 'Campaign target access denied');

    expect(DB::table('campaign_snapshots')->where('id', $snapshot->id)->count())->toBe(0)
        ->and(DB::table('campaign_targets')->where('id', $foreignTarget->id)->count())->toBe(0)
        ->and(fn () => $repository->findCampaign($outside, $campaign->id))
        ->toThrow(AuthorizationException::class, 'Campaign access denied');
});

it('rejects approval before needs-approval state without leaving approval or event rows', function () {
    $workspaceId = task0038PersistenceWorkspace('approval-state');
    $contentVersionId = task0038PersistenceContentVersion($workspaceId, 'approval-state');
    $contactId = task0038PersistenceContact($workspaceId, 'approval-state');
    $repository = app(DatabaseCampaignRepository::class);
    $at = new DateTimeImmutable('2026-09-21T18:00:00+00:00');

    $campaign = task0038PersistenceCampaign($workspaceId, 'approval-state', $at);
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created($campaign, (string) Str::uuid(), 'author', null, [], 'event-approval-state-create', $at),
    );

    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $contactId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-21T18:01:00+00:00'),
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
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$target],
        idempotencyKey: 'snapshot-approval-state',
        createdByActorId: 'author',
        createdAt: new DateTimeImmutable('2026-09-21T18:01:00+00:00'),
    );
    $repository->appendSnapshot(
        $snapshot,
        CampaignEvent::snapshotCreated(
            $snapshot,
            (string) Str::uuid(),
            'author',
            null,
            [],
            'event-approval-state-snapshot',
            new DateTimeImmutable('2026-09-21T18:01:00+00:00'),
        ),
    );

    $decision = new CampaignApprovalDecision(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaign->id,
        snapshotId: $snapshot->id,
        targetSetHash: $snapshot->targetSetHash,
        outcome: CampaignApprovalOutcome::Approved,
        actorId: 'approver',
        actorRole: 'campaign-approver',
        reason: null,
        capabilityEvidenceIds: [],
        supersedesDecisionId: null,
        expiresAt: null,
        idempotencyKey: 'approval-too-early',
        occurredAt: new DateTimeImmutable('2026-09-21T18:02:00+00:00'),
    );
    $event = CampaignEvent::approvalRecorded(
        $decision,
        (string) Str::uuid(),
        [],
        'event-approval-too-early',
    );

    expect(fn () => $repository->appendApproval($decision, $event))
        ->toThrow(InvalidArgumentException::class, 'requires needs_approval lifecycle state');

    expect(DB::table('campaign_approval_decisions')->where('id', $decision->id)->count())->toBe(0)
        ->and(DB::table('campaign_events')->where('idempotency_key', 'event-approval-too-early')->count())->toBe(0);
});

it('rejects conflicting material snapshot replay under the same idempotency key without rewriting canonical history', function () {
    $workspaceId = task0038PersistenceWorkspace('revision-replay');
    $contentVersionId = task0038PersistenceContentVersion($workspaceId, 'revision-replay');
    $firstContact = task0038PersistenceContact($workspaceId, 'revision-replay-a');
    $secondContact = task0038PersistenceContact($workspaceId, 'revision-replay-b');
    $repository = app(DatabaseCampaignRepository::class);
    $at = new DateTimeImmutable('2026-09-22T04:00:00+00:00');

    $campaign = task0038PersistenceCampaign($workspaceId, 'revision-replay', $at);
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created($campaign, (string) Str::uuid(), 'author', null, [], 'revision-replay-create', $at),
    );

    $firstTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $firstContact,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-22T04:01:00+00:00'),
    );
    $first = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaign->id,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$firstTarget],
        idempotencyKey: 'revision-replay-snapshot',
        createdByActorId: 'author',
        createdAt: new DateTimeImmutable('2026-09-22T04:01:00+00:00'),
    );
    $repository->appendSnapshot(
        $first,
        CampaignEvent::snapshotCreated(
            $first,
            (string) Str::uuid(),
            'author',
            null,
            [],
            'revision-replay-event',
            new DateTimeImmutable('2026-09-22T04:01:00+00:00'),
        ),
    );

    $secondTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $secondContact,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-22T04:02:00+00:00'),
    );
    $conflicting = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaign->id,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$secondTarget],
        idempotencyKey: 'revision-replay-snapshot',
        createdByActorId: 'author',
        createdAt: new DateTimeImmutable('2026-09-22T04:02:00+00:00'),
    );

    expect(fn () => $repository->appendSnapshot(
        $conflicting,
        CampaignEvent::snapshotCreated(
            $conflicting,
            (string) Str::uuid(),
            'author',
            null,
            [],
            'revision-replay-conflict-event',
            new DateTimeImmutable('2026-09-22T04:02:00+00:00'),
        ),
    ))->toThrow(InvalidArgumentException::class, 'replay');

    expect(DB::table('campaign_snapshots')->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(DB::table('campaign_targets')->where('snapshot_id', $first->id)->count())->toBe(1)
        ->and(DB::table('campaign_events')->where('idempotency_key', 'revision-replay-conflict-event')->count())->toBe(0)
        ->and($repository->latestSnapshot($workspaceId, $campaign->id)?->targetSetHash)->toBe($first->targetSetHash);
});

it('pins canonical identity list and tag targets to exact workspace-isolated materialized membership', function () {
    $inside = task0038PersistenceWorkspace('canonical-targets-inside');
    $outside = task0038PersistenceWorkspace('canonical-targets-outside');
    $contentVersionId = task0038PersistenceContentVersion($inside, 'canonical-targets');
    $contactA = task0038PersistenceContact($inside, 'canonical-a');
    $contactB = task0038PersistenceContact($inside, 'canonical-b');
    $foreignContact = task0038PersistenceContact($outside, 'canonical-foreign');
    $identityId = task0038PersistenceContactIdentity($inside, $contactA, 'canonical-a');
    $listId = task0038PersistenceList($inside, [$contactA, $contactB], 'canonical');
    $tagId = task0038PersistenceTag($inside, [$contactB], 'canonical');

    $repository = app(DatabaseCampaignRepository::class);
    $at = new DateTimeImmutable('2026-09-22T06:00:00+00:00');
    $campaign = task0038PersistenceCampaign($inside, 'canonical-targets', $at);
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created(
            $campaign,
            (string) Str::uuid(),
            'task0038-author',
            'Canonical target certification.',
            [],
            'canonical-targets-create',
            $at,
        ),
    );

    $identityTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        kind: CampaignTargetKind::ContactIdentity,
        canonicalReferenceId: $identityId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: ['selection' => 'exact-identity'],
        createdAt: new DateTimeImmutable('2026-09-22T06:01:00+00:00'),
    );
    $listTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        kind: CampaignTargetKind::ContactList,
        canonicalReferenceId: $listId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [
            'selection' => 'materialized-list',
            'materialized_contact_ids' => [$contactB, $contactA],
        ],
        createdAt: new DateTimeImmutable('2026-09-22T06:01:00+00:00'),
    );
    $tagTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        kind: CampaignTargetKind::Tag,
        canonicalReferenceId: $tagId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [
            'selection' => 'materialized-tag',
            'materialized_contact_ids' => [$contactB],
        ],
        createdAt: new DateTimeImmutable('2026-09-22T06:01:00+00:00'),
    );

    $snapshot = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        campaignId: $campaign->id,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$tagTarget, $identityTarget, $listTarget],
        idempotencyKey: 'canonical-targets-v1',
        createdByActorId: 'task0038-author',
        createdAt: new DateTimeImmutable('2026-09-22T06:01:00+00:00'),
    );
    $repository->appendSnapshot(
        $snapshot,
        CampaignEvent::snapshotCreated(
            $snapshot,
            (string) Str::uuid(),
            'task0038-author',
            'Pin exact canonical target set.',
            [],
            'canonical-targets-snapshot',
            new DateTimeImmutable('2026-09-22T06:01:00+00:00'),
        ),
    );

    expect(DB::table('campaign_targets')->where('snapshot_id', $snapshot->id)->count())->toBe(3)
        ->and($repository->latestSnapshot($inside, $campaign->id)?->targetSetHash)->toBe($snapshot->targetSetHash);

    $foreignRevision = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        campaignId: $campaign->id,
        parentSnapshotId: $snapshot->id,
        versionNumber: 2,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [
            new CampaignTargetBinding(
                id: (string) Str::uuid(),
                workspaceId: $inside,
                kind: CampaignTargetKind::ContactList,
                canonicalReferenceId: $listId,
                channel: 'email',
                providerConnectionId: null,
                capabilityEvidenceId: null,
                metadata: [
                    'selection' => 'foreign-materialization',
                    'materialized_contact_ids' => [$contactA, $foreignContact],
                ],
                createdAt: new DateTimeImmutable('2026-09-22T06:02:00+00:00'),
            ),
        ],
        idempotencyKey: 'canonical-targets-foreign-v2',
        createdByActorId: 'task0038-author',
        createdAt: new DateTimeImmutable('2026-09-22T06:02:00+00:00'),
    );

    expect(fn () => $repository->appendSnapshot(
        $foreignRevision,
        CampaignEvent::snapshotCreated(
            $foreignRevision,
            (string) Str::uuid(),
            'task0038-author',
            null,
            [],
            'canonical-targets-foreign-event',
            new DateTimeImmutable('2026-09-22T06:02:00+00:00'),
        ),
    ))->toThrow(AuthorizationException::class, 'materialized target contact access denied');

    $driftRevision = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        campaignId: $campaign->id,
        parentSnapshotId: $snapshot->id,
        versionNumber: 2,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [
            new CampaignTargetBinding(
                id: (string) Str::uuid(),
                workspaceId: $inside,
                kind: CampaignTargetKind::ContactList,
                canonicalReferenceId: $listId,
                channel: 'email',
                providerConnectionId: null,
                capabilityEvidenceId: null,
                metadata: [
                    'selection' => 'incomplete-materialization',
                    'materialized_contact_ids' => [$contactA],
                ],
                createdAt: new DateTimeImmutable('2026-09-22T06:03:00+00:00'),
            ),
        ],
        idempotencyKey: 'canonical-targets-drift-v2',
        createdByActorId: 'task0038-author',
        createdAt: new DateTimeImmutable('2026-09-22T06:03:00+00:00'),
    );

    expect(fn () => $repository->appendSnapshot(
        $driftRevision,
        CampaignEvent::snapshotCreated(
            $driftRevision,
            (string) Str::uuid(),
            'task0038-author',
            null,
            [],
            'canonical-targets-drift-event',
            new DateTimeImmutable('2026-09-22T06:03:00+00:00'),
        ),
    ))->toThrow(InvalidArgumentException::class, 'materialized target set does not match canonical membership');

    expect(DB::table('campaign_snapshots')->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(DB::table('campaign_events')->whereIn('idempotency_key', [
            'canonical-targets-foreign-event',
            'canonical-targets-drift-event',
        ])->count())->toBe(0);
});
