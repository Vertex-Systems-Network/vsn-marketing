<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Publishing\Application\Governance\CampaignGovernanceService;
use App\Modules\Publishing\Application\Scheduling\CampaignCalendarService;
use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/** @return array{organization: Organization, workspace: Workspace, user: User, context: TenantContext} */
function task0039CalendarActor(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Task0039 '.$suffix,
        'slug' => 'task0039-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Task0039 '.$suffix,
        'slug' => 'task0039-'.$suffix,
    ]);
    $user = User::query()->create([
        'name' => 'Task0039 '.$suffix,
        'email' => $suffix.'@task0039.test',
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

/** @param list<string> $permissions */
function task0039CalendarGrant(
    User $user,
    string $workspaceId,
    string $roleKey,
    array $permissions,
): void {
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, $workspaceId);
    $roleId = $roles->createRole($workspaceId, $roleKey, $roleKey);

    foreach ($permissions as $permission) {
        $roles->grantPermission($roleId, $permission);
    }

    $roles->assignRole($membership, $roleId);
}

/**
 * @param  array{organization: Organization, workspace: Workspace, user: User, context: TenantContext}  $actor
 * @param  array<string, mixed>|null  $intendedExecution
 * @return array{campaign: Campaign, snapshot: CampaignSnapshot, approver: User}
 */
function task0039CalendarFixture(
    array $actor,
    string $suffix,
    string $localAt = '2026-07-15T09:30:00',
    string $timezone = 'America/New_York',
    string $approvalExpiry = '2026-07-15T15:00:00+00:00',
    string $intentAt = '2026-07-15T11:00:00+00:00',
    ?array $intendedExecution = null,
): array {
    $workspaceId = (string) $actor['workspace']->getKey();
    task0039CalendarGrant(
        $actor['user'],
        $workspaceId,
        'calendar-editor-'.$suffix,
        [PermissionCatalog::CAMPAIGN_SEND],
    );

    $approver = User::query()->create([
        'name' => 'Task0039 approver '.$suffix,
        'email' => 'approver-'.$suffix.'@task0039.test',
        'password' => Hash::make('secret-pass'),
    ]);
    $approverRole = 'calendar-approver-'.$suffix;
    task0039CalendarGrant(
        $approver,
        $workspaceId,
        $approverRole,
        [PermissionCatalog::CAMPAIGN_APPROVE],
    );

    $documentId = (string) Str::uuid();
    $contentVersionId = (string) Str::uuid();
    $contactId = (string) Str::uuid();
    $createdAt = new DateTimeImmutable('2026-07-15T10:00:00+00:00');

    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'Calendar content '.$suffix,
        'lifecycle' => 'active',
        'created_by_actor_id' => (string) $actor['user']->getKey(),
        'audit_provenance' => json_encode(['source' => 'task0039-security'], JSON_THROW_ON_ERROR),
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
        'audit_provenance' => json_encode(['source' => 'task0039-security'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'calendar-content-'.$suffix,
        'created_by_actor_id' => (string) $actor['user']->getKey(),
        'created_at' => $createdAt,
    ]);
    DB::table('contacts')->insert([
        'id' => $contactId,
        'workspace_id' => $workspaceId,
        'brand_id' => null,
        'company_id' => null,
        'first_name' => 'Calendar',
        'last_name' => $suffix,
        'display_name' => 'Calendar '.$suffix,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $repository = app(DatabaseCampaignRepository::class);
    $campaign = Campaign::draft(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Calendar campaign '.$suffix,
        idempotencyKey: 'calendar-campaign-'.$suffix,
        createdByActorId: (string) $actor['user']->getKey(),
        createdAt: $createdAt,
    );
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created(
            campaign: $campaign,
            id: (string) Str::uuid(),
            actorId: (string) $actor['user']->getKey(),
            reason: 'Created for TASK-0039 calendar test.',
            evidence: [],
            idempotencyKey: 'calendar-created-'.$suffix,
            occurredAt: $createdAt,
        ),
    );

    $reviewAt = new DateTimeImmutable('2026-07-15T10:01:00+00:00');
    $review = $campaign->transitionTo(CampaignStatus::Review, $reviewAt);
    $repository->transitionCampaign(
        $review,
        $campaign->stateVersion,
        CampaignEvent::transitioned(
            before: $campaign,
            after: $review,
            id: (string) Str::uuid(),
            actorId: (string) $actor['user']->getKey(),
            reason: 'Review calendar schedule.',
            evidence: [],
            idempotencyKey: 'calendar-review-'.$suffix,
            occurredAt: $reviewAt,
        ),
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
        createdAt: new DateTimeImmutable('2026-07-15T10:02:00+00:00'),
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
        intendedExecution: $intendedExecution ?? [
            'mode' => 'fixed_instant',
            'timezone' => $timezone,
            'at' => $localAt,
        ],
        targets: [$target],
        idempotencyKey: 'calendar-snapshot-'.$suffix,
        createdByActorId: (string) $actor['user']->getKey(),
        createdAt: new DateTimeImmutable('2026-07-15T10:02:00+00:00'),
    );
    $repository->appendSnapshot(
        $snapshot,
        CampaignEvent::snapshotCreated(
            snapshot: $snapshot,
            id: (string) Str::uuid(),
            actorId: (string) $actor['user']->getKey(),
            reason: 'Pin fixed instant.',
            evidence: [],
            idempotencyKey: 'calendar-snapshot-event-'.$suffix,
            occurredAt: new DateTimeImmutable('2026-07-15T10:02:00+00:00'),
        ),
    );

    $needsApprovalAt = new DateTimeImmutable('2026-07-15T10:03:00+00:00');
    $needsApproval = $review->transitionTo(CampaignStatus::NeedsApproval, $needsApprovalAt);
    $repository->transitionCampaign(
        $needsApproval,
        $review->stateVersion,
        CampaignEvent::transitioned(
            before: $review,
            after: $needsApproval,
            id: (string) Str::uuid(),
            actorId: (string) $actor['user']->getKey(),
            reason: 'Request calendar approval.',
            evidence: ['snapshot_id' => $snapshot->id],
            idempotencyKey: 'calendar-needs-approval-'.$suffix,
            occurredAt: $needsApprovalAt,
        ),
    );

    $decision = new CampaignApprovalDecision(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaign->id,
        snapshotId: $snapshot->id,
        targetSetHash: $snapshot->targetSetHash,
        outcome: CampaignApprovalOutcome::Approved,
        actorId: (string) $approver->getKey(),
        actorRole: $approverRole,
        reason: 'Approve calendar execution.',
        capabilityEvidenceIds: [],
        supersedesDecisionId: null,
        expiresAt: new DateTimeImmutable($approvalExpiry),
        idempotencyKey: 'calendar-approval-'.$suffix,
        occurredAt: new DateTimeImmutable('2026-07-15T10:04:00+00:00'),
    );
    $repository->appendApproval(
        $decision,
        CampaignEvent::approvalRecorded(
            decision: $decision,
            id: (string) Str::uuid(),
            evidence: ['snapshot_hash' => $snapshot->snapshotHash],
            idempotencyKey: 'calendar-approval-event-'.$suffix,
        ),
    );

    $approvedAt = new DateTimeImmutable('2026-07-15T10:05:00+00:00');
    $approved = $needsApproval->transitionTo(CampaignStatus::Approved, $approvedAt);
    $repository->transitionCampaign(
        $approved,
        $needsApproval->stateVersion,
        CampaignEvent::transitioned(
            before: $needsApproval,
            after: $approved,
            id: (string) Str::uuid(),
            actorId: (string) $approver->getKey(),
            reason: 'Calendar approved.',
            evidence: ['approval_id' => $decision->id],
            idempotencyKey: 'calendar-approved-'.$suffix,
            occurredAt: $approvedAt,
        ),
    );

    $scheduledIntent = app(CampaignGovernanceService::class)->scheduleIntent(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $campaign->id,
        eventId: (string) Str::uuid(),
        eventIdempotencyKey: 'calendar-intent-'.$suffix,
        reason: 'Record calendar intent.',
        at: new DateTimeImmutable($intentAt),
    );

    return ['campaign' => $scheduledIntent, 'snapshot' => $snapshot, 'approver' => $approver];
}

it('creates and replays an authorized fixed-instant schedule bound to the exact approved snapshot', function () {
    $actor = task0039CalendarActor('fixed-authorized');
    $fixture = task0039CalendarFixture($actor, 'fixed-authorized');
    $service = app(CampaignCalendarService::class);
    $scheduleId = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-15T11:01:00+00:00');

    $stored = $service->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: $scheduleId,
        idempotencyKey: 'fixed-authorized-schedule',
        at: $at,
    );
    $replayed = $service->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: $scheduleId,
        idempotencyKey: 'fixed-authorized-schedule',
        at: new DateTimeImmutable('2026-07-15T16:00:00+00:00'),
    );

    expect($stored->resolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and($stored->timezoneId)->toBe('America/New_York')
        ->and($stored->localScheduledAt)->toBe('2026-07-15T09:30:00')
        ->and($replayed->id)->toBe($stored->id)
        ->and(DB::table('campaign_schedules')->count())->toBe(1);
});

it('denies scheduling without campaign send authority', function () {
    $actor = task0039CalendarActor('authorized-owner');
    $fixture = task0039CalendarFixture($actor, 'authorized-owner');
    $unauthorized = User::query()->create([
        'name' => 'Unauthorized scheduler',
        'email' => 'unauthorized-scheduler@task0039.test',
        'password' => Hash::make('secret-pass'),
    ]);
    app(WorkspaceRoleManager::class)->addMember($unauthorized, (string) $actor['workspace']->getKey());
    $context = new TenantContext(
        organizationId: (string) $actor['organization']->getKey(),
        workspaceId: (string) $actor['workspace']->getKey(),
        brandId: null,
        actorId: (string) $unauthorized->getKey(),
    );

    expect(fn () => app(CampaignCalendarService::class)->scheduleFixedInstant(
        actor: $unauthorized,
        context: $context,
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'unauthorized-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'campaign.send');
});

it('fails closed when approval has expired before schedule creation', function () {
    $actor = task0039CalendarActor('expired');
    $fixture = task0039CalendarFixture(
        $actor,
        'expired',
        approvalExpiry: '2026-07-15T11:30:00+00:00',
    );

    expect(fn () => app(CampaignCalendarService::class)->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'expired-schedule',
        at: new DateTimeImmutable('2026-07-15T11:31:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'approval_expired');

    expect(DB::table('campaign_schedules')->count())->toBe(0);
});

it('fails closed on ambiguous DST local time before entering scheduled intent or persisting a schedule', function () {
    $actor = task0039CalendarActor('dst-overlap');

    expect(fn () => task0039CalendarFixture(
        $actor,
        'dst-overlap',
        localAt: '2026-11-01T01:30:00',
        approvalExpiry: '2026-11-01T12:00:00+00:00',
        intentAt: '2026-10-31T12:00:00+00:00',
    ))->toThrow(InvalidArgumentException::class, 'unambiguous local wall-clock time');

    expect(DB::table('campaign_schedules')->count())->toBe(0)
        ->and(DB::table('campaigns')->where('status', 'scheduled_intent')->count())->toBe(0);
});

it('pins queue schedules to the exact rule version despite later rule drift and replays after expiry', function () {
    $actor = task0039CalendarActor('queue-versioned');
    $workspaceId = (string) $actor['workspace']->getKey();
    task0039CalendarGrant(
        $actor['user'],
        $workspaceId,
        'queue-versioned-editor',
        [PermissionCatalog::CAMPAIGN_SEND],
    );

    $calendar = app(CampaignCalendarService::class);
    $ruleId = (string) Str::uuid();
    $ruleV1 = $calendar->createQueueRuleSet(
        actor: $actor['user'],
        context: $actor['context'],
        ruleSetId: $ruleId,
        channel: 'email',
        timezoneId: 'America/New_York',
        slots: [
            ['weekday' => 3, 'local_time' => '09:30:00'],
            ['weekday' => 5, 'local_time' => '08:00:00'],
        ],
        idempotencyKey: 'queue-versioned-rule-v1',
        at: new DateTimeImmutable('2026-07-15T10:00:00+00:00'),
    );

    $fixture = task0039CalendarFixture(
        $actor,
        'queue-versioned',
        approvalExpiry: '2026-07-15T15:00:00+00:00',
        intentAt: '2026-07-15T11:00:00+00:00',
        intendedExecution: [
            'mode' => 'queue_next_slot',
            'rule_set_id' => $ruleV1->id,
            'channel' => 'email',
        ],
    );

    $scheduleId = (string) Str::uuid();
    $stored = $calendar->scheduleQueueNextSlot(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: $scheduleId,
        idempotencyKey: 'queue-versioned-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $ruleV2 = $calendar->createQueueRuleSet(
        actor: $actor['user'],
        context: $actor['context'],
        ruleSetId: (string) Str::uuid(),
        channel: 'email',
        timezoneId: 'UTC',
        slots: [
            ['weekday' => 3, 'local_time' => '20:00:00'],
        ],
        idempotencyKey: 'queue-versioned-rule-v2',
        at: new DateTimeImmutable('2026-07-15T11:02:00+00:00'),
    );

    $replayed = $calendar->scheduleQueueNextSlot(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: $scheduleId,
        idempotencyKey: 'queue-versioned-schedule',
        at: new DateTimeImmutable('2026-07-15T16:00:00+00:00'),
    );

    expect($ruleV1->versionNumber)->toBe(1)
        ->and($ruleV2->versionNumber)->toBe(2)
        ->and($stored->strategy->value)->toBe('queue_next_slot')
        ->and($stored->ruleSetId)->toBe($ruleV1->id)
        ->and($stored->ruleVersion)->toBe(1)
        ->and($stored->ruleHash)->toBe($ruleV1->ruleHash)
        ->and($stored->timezoneId)->toBe('America/New_York')
        ->and($stored->localScheduledAt)->toBe('2026-07-15T09:30:00')
        ->and($stored->resolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and($replayed->scheduleHash)->toBe($stored->scheduleHash)
        ->and(DB::table('campaign_queue_schedule_bindings')->count())->toBe(1);
});

it('rejects a queue intent whose pinned rule belongs to another workspace', function () {
    $owner = task0039CalendarActor('queue-rule-owner');
    $foreign = task0039CalendarActor('queue-rule-foreign');

    task0039CalendarGrant(
        $foreign['user'],
        (string) $foreign['workspace']->getKey(),
        'queue-rule-foreign-editor',
        [PermissionCatalog::CAMPAIGN_SEND],
    );

    $rule = app(CampaignCalendarService::class)->createQueueRuleSet(
        actor: $foreign['user'],
        context: $foreign['context'],
        ruleSetId: (string) Str::uuid(),
        channel: 'email',
        timezoneId: 'UTC',
        slots: [
            ['weekday' => 3, 'local_time' => '12:00:00'],
        ],
        idempotencyKey: 'queue-rule-foreign-v1',
        at: new DateTimeImmutable('2026-07-15T10:00:00+00:00'),
    );

    expect(fn () => task0039CalendarFixture(
        $owner,
        'queue-rule-owner',
        intendedExecution: [
            'mode' => 'queue_next_slot',
            'rule_set_id' => $rule->id,
            'channel' => 'email',
        ],
    ))->toThrow(AuthorizationException::class, 'Campaign schedule rule reference access denied.');

    expect(DB::table('campaign_schedules')->count())->toBe(0);
});

it('rejects queue intent when the pinned channel is absent from immutable snapshot targets', function () {
    $actor = task0039CalendarActor('queue-channel-mismatch');
    $workspaceId = (string) $actor['workspace']->getKey();
    task0039CalendarGrant(
        $actor['user'],
        $workspaceId,
        'queue-channel-mismatch-editor',
        [PermissionCatalog::CAMPAIGN_SEND],
    );

    $rule = app(CampaignCalendarService::class)->createQueueRuleSet(
        actor: $actor['user'],
        context: $actor['context'],
        ruleSetId: (string) Str::uuid(),
        channel: 'email',
        timezoneId: 'UTC',
        slots: [
            ['weekday' => 3, 'local_time' => '12:00:00'],
        ],
        idempotencyKey: 'queue-channel-mismatch-rule',
        at: new DateTimeImmutable('2026-07-15T10:00:00+00:00'),
    );

    expect(fn () => task0039CalendarFixture(
        $actor,
        'queue-channel-mismatch',
        intendedExecution: [
            'mode' => 'queue_next_slot',
            'rule_set_id' => $rule->id,
            'channel' => 'sms',
        ],
    ))->toThrow(
        InvalidArgumentException::class,
        'channel is not present in the immutable snapshot target set',
    );

    expect(DB::table('campaign_schedules')->count())->toBe(0);
});
