<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Publishing\Application\Governance\CampaignGovernanceService;
use App\Modules\Publishing\Application\Scheduling\CampaignCalendarService;
use App\Modules\Publishing\Application\Scheduling\CampaignScheduleDueClaimService;
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

    $ruleV1Replay = $calendar->createQueueRuleSet(
        actor: $actor['user'],
        context: $actor['context'],
        ruleSetId: $ruleV1->id,
        channel: 'email',
        timezoneId: 'America/New_York',
        slots: [
            ['weekday' => 3, 'local_time' => '09:30:00'],
            ['weekday' => 5, 'local_time' => '08:00:00'],
        ],
        idempotencyKey: 'queue-versioned-rule-v1',
        at: new DateTimeImmutable('2026-07-15T16:00:00+00:00'),
    );

    expect(fn () => $calendar->createQueueRuleSet(
        actor: $actor['user'],
        context: $actor['context'],
        ruleSetId: $ruleV1->id,
        channel: 'email',
        timezoneId: 'America/New_York',
        slots: [
            ['weekday' => 3, 'local_time' => '10:30:00'],
        ],
        idempotencyKey: 'queue-versioned-rule-v1',
        at: new DateTimeImmutable('2026-07-15T16:01:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'Campaign schedule rule replay conflicts with existing immutable rule state.',
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
        ->and($ruleV1Replay->id)->toBe($ruleV1->id)
        ->and($ruleV1Replay->ruleHash)->toBe($ruleV1->ruleHash)
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

it('records cancellation as terminal immutable history and replays after the source occurrence is due', function () {
    $actor = task0039CalendarActor('cancel-schedule-history');
    $fixture = task0039CalendarFixture($actor, 'cancel-schedule-history');
    $calendar = app(CampaignCalendarService::class);
    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'cancel-history-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $mutationId = (string) Str::uuid();
    $cancelled = $calendar->cancelSchedule(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        mutationId: $mutationId,
        mutationIdempotencyKey: 'cancel-history-mutation',
        reason: 'Operator cancelled before execution.',
        at: new DateTimeImmutable('2026-07-15T11:05:00+00:00'),
    );
    $replayed = $calendar->cancelSchedule(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        mutationId: $mutationId,
        mutationIdempotencyKey: 'cancel-history-mutation',
        reason: 'Operator cancelled before execution.',
        at: new DateTimeImmutable('2026-07-15T14:00:00+00:00'),
    );

    expect($cancelled->type->value)->toBe('cancelled')
        ->and($cancelled->previousScheduleId)->toBe($schedule->id)
        ->and($cancelled->replacementScheduleId)->toBeNull()
        ->and($replayed->mutationHash)->toBe($cancelled->mutationHash)
        ->and(DB::table('campaign_schedules')->count())->toBe(1)
        ->and(DB::table('campaign_schedule_mutations')->count())->toBe(1);

    expect(fn () => $calendar->cancelSchedule(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        mutationId: (string) Str::uuid(),
        mutationIdempotencyKey: 'cancel-history-conflict',
        reason: 'Conflicting cancellation.',
        at: new DateTimeImmutable('2026-07-15T11:06:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'already has terminal reschedule/cancellation history',
    );
});

it('denies schedule cancellation without campaign send authority', function () {
    $actor = task0039CalendarActor('cancel-authority-owner');
    $fixture = task0039CalendarFixture($actor, 'cancel-authority-owner');
    $calendar = app(CampaignCalendarService::class);
    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'cancel-authority-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $unauthorized = User::query()->create([
        'name' => 'Unauthorized schedule canceller',
        'email' => 'unauthorized-cancel@task0039.test',
        'password' => Hash::make('secret-pass'),
    ]);
    app(WorkspaceRoleManager::class)->addMember(
        $unauthorized,
        (string) $actor['workspace']->getKey(),
    );
    $context = new TenantContext(
        organizationId: (string) $actor['organization']->getKey(),
        workspaceId: (string) $actor['workspace']->getKey(),
        brandId: null,
        actorId: (string) $unauthorized->getKey(),
    );

    expect(fn () => $calendar->cancelSchedule(
        actor: $unauthorized,
        context: $context,
        scheduleId: $schedule->id,
        mutationId: (string) Str::uuid(),
        mutationIdempotencyKey: 'cancel-authority-denied',
        reason: 'Unauthorized cancellation.',
        at: new DateTimeImmutable('2026-07-15T11:05:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'campaign.send');

    expect(DB::table('campaign_schedule_mutations')->count())->toBe(0);
});

it('requires material fixed-instant revisions to regain approval before replacement scheduling', function () {
    $suffix = 'fixed-reschedule-approval';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture(
        $actor,
        $suffix,
        approvalExpiry: '2026-07-15T15:00:00+00:00',
    );
    $calendar = app(CampaignCalendarService::class);
    $governance = app(CampaignGovernanceService::class);

    $previous = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'fixed-reschedule-original',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    task0039CalendarGrant(
        $actor['user'],
        (string) $actor['workspace']->getKey(),
        'fixed-reschedule-editor',
        [PermissionCatalog::CAMPAIGN_CREATE],
    );

    $old = $fixture['snapshot'];
    $oldTarget = $old->targets[0];
    $replacementTarget = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $oldTarget->workspaceId,
        kind: $oldTarget->kind,
        canonicalReferenceId: $oldTarget->canonicalReferenceId,
        channel: $oldTarget->channel,
        providerConnectionId: $oldTarget->providerConnectionId,
        capabilityEvidenceId: $oldTarget->capabilityEvidenceId,
        metadata: $oldTarget->metadata,
        createdAt: new DateTimeImmutable('2026-07-15T11:02:00+00:00'),
    );
    $replacementSnapshot = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $old->workspaceId,
        campaignId: $old->campaignId,
        parentSnapshotId: $old->id,
        versionNumber: 2,
        contentVersionId: $old->contentVersionId,
        templateVersionId: $old->templateVersionId,
        componentVersionIds: $old->componentVersionIds,
        assetReferenceIds: $old->assetReferenceIds,
        capabilityEvidenceIds: $old->capabilityEvidenceIds,
        brandReference: $old->brandReference,
        intendedExecution: [
            'mode' => 'fixed_instant',
            'timezone' => 'America/New_York',
            'at' => '2026-07-15T10:30:00',
        ],
        targets: [$replacementTarget],
        idempotencyKey: 'fixed-reschedule-snapshot-v2',
        createdByActorId: (string) $actor['user']->getKey(),
        createdAt: new DateTimeImmutable('2026-07-15T11:02:00+00:00'),
    );

    $governance->appendMaterialRevision(
        actor: $actor['user'],
        context: $actor['context'],
        snapshot: $replacementSnapshot,
        snapshotEventId: (string) Str::uuid(),
        snapshotEventIdempotencyKey: 'fixed-reschedule-snapshot-v2-event',
        invalidationEventId: (string) Str::uuid(),
        invalidationEventIdempotencyKey: 'fixed-reschedule-invalidation',
        reason: 'Move approved execution to a new fixed instant.',
        at: new DateTimeImmutable('2026-07-15T11:02:00+00:00'),
    );

    $replacementScheduleId = (string) Str::uuid();
    $mutationId = (string) Str::uuid();

    expect(fn () => $calendar->rescheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        previousScheduleId: $previous->id,
        replacementSnapshotId: $replacementSnapshot->id,
        replacementScheduleId: $replacementScheduleId,
        replacementScheduleIdempotencyKey: 'fixed-reschedule-replacement',
        mutationId: $mutationId,
        mutationIdempotencyKey: 'fixed-reschedule-mutation',
        reason: 'Apply approved replacement instant.',
        at: new DateTimeImmutable('2026-07-15T11:03:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'requires scheduled_intent lifecycle state',
    );

    expect(DB::table('campaign_schedules')->count())->toBe(1)
        ->and(DB::table('campaign_schedule_mutations')->count())->toBe(0);

    $governance->approve(
        approver: $fixture['approver'],
        context: new TenantContext(
            organizationId: (string) $actor['organization']->getKey(),
            workspaceId: (string) $actor['workspace']->getKey(),
            brandId: null,
            actorId: (string) $fixture['approver']->getKey(),
        ),
        campaignId: $fixture['campaign']->id,
        snapshotId: $replacementSnapshot->id,
        roleKey: 'calendar-approver-'.$suffix,
        decisionId: (string) Str::uuid(),
        decisionIdempotencyKey: 'fixed-reschedule-approval-v2',
        approvalEventId: (string) Str::uuid(),
        approvalEventIdempotencyKey: 'fixed-reschedule-approval-v2-event',
        transitionEventId: (string) Str::uuid(),
        transitionEventIdempotencyKey: 'fixed-reschedule-approved-v2',
        reason: 'Approve revised execution instant.',
        expiresAt: new DateTimeImmutable('2026-07-15T16:00:00+00:00'),
        at: new DateTimeImmutable('2026-07-15T11:04:00+00:00'),
    );

    $governance->scheduleIntent(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        eventId: (string) Str::uuid(),
        eventIdempotencyKey: 'fixed-reschedule-intent-v2',
        reason: 'Record approved replacement intent.',
        at: new DateTimeImmutable('2026-07-15T11:05:00+00:00'),
    );

    $mutation = $calendar->rescheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        previousScheduleId: $previous->id,
        replacementSnapshotId: $replacementSnapshot->id,
        replacementScheduleId: $replacementScheduleId,
        replacementScheduleIdempotencyKey: 'fixed-reschedule-replacement',
        mutationId: $mutationId,
        mutationIdempotencyKey: 'fixed-reschedule-mutation',
        reason: 'Apply approved replacement instant.',
        at: new DateTimeImmutable('2026-07-15T11:06:00+00:00'),
    );

    $replayed = $calendar->rescheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        previousScheduleId: $previous->id,
        replacementSnapshotId: $replacementSnapshot->id,
        replacementScheduleId: $replacementScheduleId,
        replacementScheduleIdempotencyKey: 'fixed-reschedule-replacement',
        mutationId: $mutationId,
        mutationIdempotencyKey: 'fixed-reschedule-mutation',
        reason: 'Apply approved replacement instant.',
        at: new DateTimeImmutable('2026-07-15T15:00:00+00:00'),
    );

    expect($mutation->type->value)->toBe('rescheduled')
        ->and($mutation->previousScheduleId)->toBe($previous->id)
        ->and($mutation->replacementScheduleId)->toBe($replacementScheduleId)
        ->and($mutation->previousResolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and($mutation->replacementResolvedAtUtc?->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T14:30:00+00:00')
        ->and($replayed->mutationHash)->toBe($mutation->mutationHash)
        ->and(DB::table('campaign_schedules')->count())->toBe(2)
        ->and(DB::table('campaign_schedule_mutations')->count())->toBe(1)
        ->and(DB::table('campaign_schedules')->where('id', $previous->id)->value('schedule_hash'))
        ->toBe($previous->scheduleHash);
});

it('records expired approval as missed needs-reschedule at the exact due boundary', function () {
    $suffix = 'missed-expired-approval';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture(
        $actor,
        $suffix,
        approvalExpiry: '2026-07-15T13:00:00+00:00',
    );
    $calendar = app(CampaignCalendarService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'missed-expired-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $outcomeId = (string) Str::uuid();
    $outcome = $calendar->recordMissedOccurrence(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        outcomeId: $outcomeId,
        idempotencyKey: 'missed-expired-outcome',
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    );

    $replayed = $calendar->recordMissedOccurrence(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        outcomeId: $outcomeId,
        idempotencyKey: 'missed-expired-outcome',
        at: new DateTimeImmutable('2026-07-15T14:00:00+00:00'),
    );

    expect($outcome->state->value)->toBe('missed_needs_reschedule')
        ->and($outcome->missedReason->value)->toBe('approval_invalid')
        ->and($outcome->approvalInvalidReason?->value)->toBe('approval_expired')
        ->and($outcome->scheduledApprovalId)->toBe($schedule->approvalId)
        ->and($outcome->observedAt->format('Y-m-d\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and($replayed->outcomeHash)->toBe($outcome->outcomeHash)
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(1)
        ->and(DB::table('campaign_schedule_mutations')->count())->toBe(0)
        ->and(DB::table('campaign_schedules')->where('id', $schedule->id)->value('schedule_hash'))
        ->toBe($schedule->scheduleHash);
});

it('records revoked approval as a deterministic missed occurrence at due time', function () {
    $suffix = 'missed-revoked-approval';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);
    $governance = app(CampaignGovernanceService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'missed-revoked-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $governance->revokeApproval(
        approver: $fixture['approver'],
        context: new TenantContext(
            organizationId: (string) $actor['organization']->getKey(),
            workspaceId: (string) $actor['workspace']->getKey(),
            brandId: null,
            actorId: (string) $fixture['approver']->getKey(),
        ),
        campaignId: $fixture['campaign']->id,
        roleKey: 'calendar-approver-'.$suffix,
        decisionId: (string) Str::uuid(),
        decisionIdempotencyKey: 'missed-revoked-decision',
        approvalEventId: (string) Str::uuid(),
        approvalEventIdempotencyKey: 'missed-revoked-approval-event',
        transitionEventId: (string) Str::uuid(),
        transitionEventIdempotencyKey: 'missed-revoked-transition',
        reason: 'Approval revoked before the occurrence became due.',
        at: new DateTimeImmutable('2026-07-15T12:00:00+00:00'),
    );

    $outcome = $calendar->recordMissedOccurrence(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        outcomeId: (string) Str::uuid(),
        idempotencyKey: 'missed-revoked-outcome',
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    );

    expect($outcome->missedReason->value)->toBe('approval_invalid')
        ->and($outcome->approvalInvalidReason?->value)->toBe('approval_revoked')
        ->and($outcome->evaluatedDecisionId)->not->toBe($schedule->approvalId)
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(1);
});

it('records a valid but late occurrence as missed instead of silently publishing late', function () {
    $suffix = 'missed-valid-late';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'missed-valid-late-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $outcome = $calendar->recordMissedOccurrence(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        outcomeId: (string) Str::uuid(),
        idempotencyKey: 'missed-valid-late-outcome',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    expect($outcome->state->value)->toBe('missed_needs_reschedule')
        ->and($outcome->missedReason->value)->toBe('execution_deadline_missed')
        ->and($outcome->approvalInvalidReason)->toBeNull()
        ->and($outcome->evaluatedDecisionId)->toBe($schedule->approvalId)
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(1);
});

it('reserves valid exact-due execution claiming for AC-6 without emitting an outcome', function () {
    $suffix = 'exact-due-ac6';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'exact-due-ac6-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    expect(fn () => $calendar->recordMissedOccurrence(
        actor: $actor['user'],
        context: $actor['context'],
        scheduleId: $schedule->id,
        outcomeId: (string) Str::uuid(),
        idempotencyKey: 'exact-due-ac6-outcome',
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'AC-6 owns execution claiming',
    );

    expect(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(0);
});

it('denies missed occurrence writes without campaign send authority', function () {
    $actor = task0039CalendarActor('missed-authority-owner');
    $fixture = task0039CalendarFixture($actor, 'missed-authority-owner');
    $calendar = app(CampaignCalendarService::class);
    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'missed-authority-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $unauthorized = User::query()->create([
        'name' => 'Unauthorized missed-outcome writer',
        'email' => 'unauthorized-missed@task0039.test',
        'password' => Hash::make('secret-pass'),
    ]);
    app(WorkspaceRoleManager::class)->addMember(
        $unauthorized,
        (string) $actor['workspace']->getKey(),
    );
    $context = new TenantContext(
        organizationId: (string) $actor['organization']->getKey(),
        workspaceId: (string) $actor['workspace']->getKey(),
        brandId: null,
        actorId: (string) $unauthorized->getKey(),
    );

    expect(fn () => $calendar->recordMissedOccurrence(
        actor: $unauthorized,
        context: $context,
        scheduleId: $schedule->id,
        outcomeId: (string) Str::uuid(),
        idempotencyKey: 'missed-authority-denied',
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'campaign.send');

    expect(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(0);
});


it('claims an exact due occurrence once and atomically emits one durable execution intent and outbox handoff', function () {
    $suffix = 'due-claim-emit';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'due-claim-emit-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $claim = $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-a',
        leaseToken: 'lease-due-claim-emit',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    );
    $replayedClaim = $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-a',
        leaseToken: 'lease-due-claim-emit',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:10+00:00'),
    );

    expect($claim)->not->toBeNull()
        ->and($claim?->state->value)->toBe('leased')
        ->and($replayedClaim?->id)->toBe($claim?->id)
        ->and(DB::table('campaign_schedule_due_claims')->count())->toBe(1)
        ->and(DB::table('campaign_schedule_due_claims')->value('lease_token_hash'))
        ->toBe(hash('sha256', 'lease-due-claim-emit'));

    $intent = $claims->emitExecutionIntent(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-a',
        leaseToken: 'lease-due-claim-emit',
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $replayedIntent = $claims->emitExecutionIntent(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-b',
        leaseToken: 'foreign-after-emission',
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    expect($intent->scheduleId)->toBe($schedule->id)
        ->and($intent->claimedAt->format('U.u'))->toBe($schedule->resolvedAtUtc->format('U.u'))
        ->and($replayedIntent->id)->toBe($intent->id)
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(1)
        ->and(DB::table('campaign_schedule_due_claims')->where('schedule_id', $schedule->id)->value('state'))
        ->toBe('emitted')
        ->and(DB::table('outbox_messages')->where('id', $intent->outboxId)->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('topic', 'publishing.campaign_schedule.execution_intent.ready')->count())
        ->toBe(1);
});

it('recovers an expired due lease with a fresh token and rejects the stale worker', function () {
    $suffix = 'stale-due-claim';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'stale-due-claim-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $first = $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-a',
        leaseToken: 'lease-stale-a',
        leaseSeconds: 30,
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    );
    expect(fn () => $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-b',
        leaseToken: 'lease-stale-b',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:10+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'actively leased by another worker',
    );

    $replacement = $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-b',
        leaseToken: 'lease-stale-b',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    );

    expect($replacement)->not->toBeNull()
        ->and($replacement?->id)->toBe($first?->id)
        ->and($replacement?->attemptNumber)->toBe(2)
        ->and($replacement?->version)->toBe(2);

    expect(fn () => $claims->emitExecutionIntent(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-a',
        leaseToken: 'lease-stale-a',
        at: new DateTimeImmutable('2026-07-15T13:30:32+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'lease token is stale or foreign');

    $intent = $claims->emitExecutionIntent(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-worker-b',
        leaseToken: 'lease-stale-b',
        at: new DateTimeImmutable('2026-07-15T13:30:33+00:00'),
    );

    expect($intent->claimVersion)->toBe(2)
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('id', $intent->outboxId)->count())->toBe(1);
});

it('routes a late unclaimed occurrence to missed needs-reschedule instead of creating a claim', function () {
    $suffix = 'late-unclaimed-claim';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'late-unclaimed-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $claim = $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-late-worker',
        leaseToken: 'lease-late-unclaimed',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    expect($claim)->toBeNull()
        ->and(DB::table('campaign_schedule_due_claims')->count())->toBe(0)
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->where('schedule_id', $schedule->id)->value('outcome_state'))
        ->toBe('missed_needs_reschedule')
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->where('schedule_id', $schedule->id)->value('missed_reason'))
        ->toBe('execution_deadline_missed')
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(0);
});

it('records invalid approval at due and never creates scheduler work', function () {
    $suffix = 'due-claim-expired';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture(
        $actor,
        $suffix,
        approvalExpiry: '2026-07-15T13:00:00+00:00',
    );
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'due-claim-expired-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    $claim = $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-expired-worker',
        leaseToken: 'lease-expired-approval',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    );

    expect($claim)->toBeNull()
        ->and(DB::table('campaign_schedule_due_claims')->count())->toBe(0)
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(0)
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->where('schedule_id', $schedule->id)->value('approval_invalid_reason'))
        ->toBe('approval_expired');
});

it('fails closed on foreign-workspace due claims before exposing scheduler state', function () {
    $owner = task0039CalendarActor('claim-owner');
    $fixture = task0039CalendarFixture($owner, 'claim-owner');
    $other = task0039CalendarActor('claim-other');
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $owner['user'],
        context: $owner['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'foreign-claim-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    expect(fn () => $claims->acquireDueClaim(
        workspaceId: $other['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-foreign-worker',
        leaseToken: 'lease-foreign-workspace',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'Campaign schedule reference access denied.');

    expect(DB::table('campaign_schedule_due_claims')->count())->toBe(0)
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(0);
});


it('rejects claiming before the canonical resolved instant', function () {
    $suffix = 'pre-due-claim';
    $actor = task0039CalendarActor($suffix);
    $fixture = task0039CalendarFixture($actor, $suffix);
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $actor['user'],
        context: $actor['context'],
        campaignId: $fixture['campaign']->id,
        snapshotId: $fixture['snapshot']->id,
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'pre-due-claim-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );

    expect(fn () => $claims->acquireDueClaim(
        workspaceId: $actor['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'scheduler-early-worker',
        leaseToken: 'lease-pre-due',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:29:59+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'cannot be claimed before its resolved UTC instant',
    );

    expect(DB::table('campaign_schedule_due_claims')->count())->toBe(0)
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(0)
        ->and(DB::table('outbox_messages')
            ->where('topic', 'publishing.campaign_schedule.execution_intent.ready')
            ->count())->toBe(0);
});
