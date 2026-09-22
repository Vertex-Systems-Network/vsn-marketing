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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped(
            'Set RUN_INFRA_INTEGRATION=true to run TASK-0039 provider-schedule PostgreSQL certification.',
        );
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0039 provider-schedule certification requires PostgreSQL.');
    }
});

/**
 * @return array{
 *     author: User,
 *     context: TenantContext,
 *     campaignId: string,
 *     snapshotId: string,
 *     providerConnectionId: string,
 *     providerCapabilityId: string
 * }
 */
function task0039ProviderSchedulePgFixture(): array
{
    $organization = Organization::query()->create([
        'name' => 'TASK-0039 Provider Schedule PG',
        'slug' => 'task0039-provider-schedule-pg',
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'TASK-0039 Provider Schedule PG',
        'slug' => 'task0039-provider-schedule-pg',
    ]);
    $workspaceId = (string) $workspace->getKey();

    $author = User::query()->create([
        'name' => 'TASK-0039 Provider Scheduler',
        'email' => 'task0039-provider-scheduler@example.test',
        'password' => Hash::make('secret-pass'),
    ]);
    $approver = User::query()->create([
        'name' => 'TASK-0039 Provider Approver',
        'email' => 'task0039-provider-approver@example.test',
        'password' => Hash::make('secret-pass'),
    ]);

    $roles = app(WorkspaceRoleManager::class);
    $authorMembership = $roles->addMember($author, $workspaceId);
    $authorRole = $roles->createRole($workspaceId, 'task0039-provider-sender', 'Provider sender');
    $roles->grantPermission($authorRole, PermissionCatalog::CAMPAIGN_SEND);
    $roles->assignRole($authorMembership, $authorRole);

    $approverMembership = $roles->addMember($approver, $workspaceId);
    $approverRoleKey = 'task0039-provider-approver';
    $approverRole = $roles->createRole($workspaceId, $approverRoleKey, 'Provider approver');
    $roles->grantPermission($approverRole, PermissionCatalog::CAMPAIGN_APPROVE);
    $roles->assignRole($approverMembership, $approverRole);

    $context = new TenantContext(
        organizationId: (string) $organization->getKey(),
        workspaceId: $workspaceId,
        brandId: null,
        actorId: (string) $author->getKey(),
    );

    $createdAt = new DateTimeImmutable('2026-07-15T10:00:00+00:00');
    $documentId = (string) Str::uuid();
    $contentVersionId = (string) Str::uuid();
    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0039 provider schedule content',
        'lifecycle' => 'active',
        'created_by_actor_id' => (string) $author->getKey(),
        'audit_provenance' => json_encode(['source' => 'task0039-provider-pg'], JSON_THROW_ON_ERROR),
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
        'audit_provenance' => json_encode(['source' => 'task0039-provider-pg'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'task0039-provider-pg-content',
        'created_by_actor_id' => (string) $author->getKey(),
        'created_at' => $createdAt,
    ]);

    $providerId = (string) Str::uuid();
    $providerConnectionId = (string) Str::uuid();
    $providerCapabilityId = (string) Str::uuid();
    $providerObservedAt = new DateTimeImmutable('2026-07-15T09:00:00+00:00');
    $providerFreshUntil = new DateTimeImmutable('2026-07-16T09:00:00+00:00');
    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $workspaceId,
        'provider_key' => 'task0039-provider-pg',
        'display_name' => 'TASK-0039 Provider PG',
        'category' => 'social',
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/task0039/provider-pg',
        'source_version' => '2026-09',
        'observed_at' => $providerObservedAt,
        'fresh_until' => $providerFreshUntil,
        'created_at' => $providerObservedAt,
        'updated_at' => $providerObservedAt,
    ]);
    DB::table('provider_connections')->insert([
        'id' => $providerConnectionId,
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'name' => 'Primary',
        'readiness_status' => 'ready',
        'auth_family' => 'oauth2',
        'secret_reference' => 'vault://task0039/provider-pg',
        'requested_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
        'granted_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
        'roles' => json_encode(['publisher'], JSON_THROW_ON_ERROR),
        'access_tier' => null,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'provider_review_status' => 'approved',
        'token_expires_at' => $providerFreshUntil,
        'refresh_supported' => true,
        'last_rotated_at' => null,
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/task0039/provider-pg/connection',
        'source_version' => '2026-09',
        'observed_at' => $providerObservedAt,
        'fresh_until' => $providerFreshUntil,
        'created_at' => $providerObservedAt,
        'updated_at' => $providerObservedAt,
    ]);
    DB::table('provider_capabilities')->insert([
        'id' => $providerCapabilityId,
        'workspace_id' => $workspaceId,
        'provider_id' => $providerId,
        'connection_id' => $providerConnectionId,
        'operation' => 'publication.schedule.remote',
        'support_status' => 'supported',
        'required_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
        'required_roles' => json_encode(['publisher'], JSON_THROW_ON_ERROR),
        'constraints' => json_encode(['remote_schedule' => true], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/task0039/provider-pg/capability',
        'source_version' => '2026-09',
        'observed_at' => $providerObservedAt,
        'fresh_until' => $providerFreshUntil,
        'created_at' => $providerObservedAt,
        'updated_at' => $providerObservedAt,
    ]);

    $repository = app(DatabaseCampaignRepository::class);
    $campaign = Campaign::draft(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'TASK-0039 provider schedule campaign',
        idempotencyKey: 'task0039-provider-pg-campaign',
        createdByActorId: (string) $author->getKey(),
        createdAt: $createdAt,
    );
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created(
            campaign: $campaign,
            id: (string) Str::uuid(),
            actorId: (string) $author->getKey(),
            reason: 'Create provider schedule PostgreSQL fixture.',
            evidence: [],
            idempotencyKey: 'task0039-provider-pg-created',
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
            actorId: (string) $author->getKey(),
            reason: 'Review provider schedule fixture.',
            evidence: [],
            idempotencyKey: 'task0039-provider-pg-review',
            occurredAt: $reviewAt,
        ),
    );

    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: CampaignTargetKind::ProviderConnection,
        canonicalReferenceId: $providerConnectionId,
        channel: 'social',
        providerConnectionId: $providerConnectionId,
        capabilityEvidenceId: $providerCapabilityId,
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
        capabilityEvidenceIds: [$providerCapabilityId],
        brandReference: [],
        intendedExecution: [
            'mode' => 'fixed_instant',
            'timezone' => 'America/New_York',
            'at' => '2026-07-15T09:30:00',
        ],
        targets: [$target],
        idempotencyKey: 'task0039-provider-pg-snapshot',
        createdByActorId: (string) $author->getKey(),
        createdAt: new DateTimeImmutable('2026-07-15T10:02:00+00:00'),
    );
    $repository->appendSnapshot(
        $snapshot,
        CampaignEvent::snapshotCreated(
            snapshot: $snapshot,
            id: (string) Str::uuid(),
            actorId: (string) $author->getKey(),
            reason: 'Pin provider schedule evidence.',
            evidence: [],
            idempotencyKey: 'task0039-provider-pg-snapshot-event',
            occurredAt: new DateTimeImmutable('2026-07-15T10:02:00+00:00'),
        ),
    );

    $needsAt = new DateTimeImmutable('2026-07-15T10:03:00+00:00');
    $needsApproval = $review->transitionTo(CampaignStatus::NeedsApproval, $needsAt);
    $repository->transitionCampaign(
        $needsApproval,
        $review->stateVersion,
        CampaignEvent::transitioned(
            before: $review,
            after: $needsApproval,
            id: (string) Str::uuid(),
            actorId: (string) $author->getKey(),
            reason: 'Request provider schedule approval.',
            evidence: ['snapshot_id' => $snapshot->id],
            idempotencyKey: 'task0039-provider-pg-needs',
            occurredAt: $needsAt,
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
        actorRole: $approverRoleKey,
        reason: 'Approve provider schedule PostgreSQL fixture.',
        capabilityEvidenceIds: [$providerCapabilityId],
        supersedesDecisionId: null,
        expiresAt: new DateTimeImmutable('2026-07-15T15:00:00+00:00'),
        idempotencyKey: 'task0039-provider-pg-approval',
        occurredAt: new DateTimeImmutable('2026-07-15T10:04:00+00:00'),
    );
    $repository->appendApproval(
        $decision,
        CampaignEvent::approvalRecorded(
            decision: $decision,
            id: (string) Str::uuid(),
            evidence: ['snapshot_hash' => $snapshot->snapshotHash],
            idempotencyKey: 'task0039-provider-pg-approval-event',
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
            reason: 'Provider schedule approved.',
            evidence: ['approval_id' => $decision->id],
            idempotencyKey: 'task0039-provider-pg-approved',
            occurredAt: $approvedAt,
        ),
    );

    $scheduledIntent = app(CampaignGovernanceService::class)->scheduleIntent(
        actor: $author,
        context: $context,
        campaignId: $campaign->id,
        eventId: (string) Str::uuid(),
        eventIdempotencyKey: 'task0039-provider-pg-intent',
        reason: 'Record canonical VSN schedule intent.',
        at: new DateTimeImmutable('2026-07-15T11:00:00+00:00'),
    );

    return [
        'author' => $author,
        'context' => $context,
        'campaignId' => $scheduledIntent->id,
        'snapshotId' => $snapshot->id,
        'providerConnectionId' => $providerConnectionId,
        'providerCapabilityId' => $providerCapabilityId,
    ];
}

it('keeps PostgreSQL schedule canonical and blocks execution after provider capability drift', function () {
    $fixture = task0039ProviderSchedulePgFixture();
    $calendar = app(CampaignCalendarService::class);
    $claims = app(CampaignScheduleDueClaimService::class);

    $schedule = $calendar->scheduleFixedInstant(
        actor: $fixture['author'],
        context: $fixture['context'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        scheduleId: (string) Str::uuid(),
        idempotencyKey: 'task0039-provider-pg-schedule',
        at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
    );
    $claims->acquireDueClaim(
        workspaceId: $fixture['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'task0039-provider-pg-worker',
        leaseToken: 'task0039-provider-pg-token',
        leaseSeconds: 60,
        at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
    );

    expect($schedule->resolvedAtUtc->format('Y-m-d\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and(DB::table('provider_capabilities')
            ->where('id', $fixture['providerCapabilityId'])
            ->value('operation'))->toBe('publication.schedule.remote');

    DB::table('provider_capabilities')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerCapabilityId'])
        ->update([
            'support_status' => 'unsupported',
            'updated_at' => new DateTimeImmutable('2026-07-15T13:30:05+00:00'),
        ]);

    expect(fn () => $claims->emitExecutionIntent(
        workspaceId: $fixture['context']->workspaceId,
        scheduleId: $schedule->id,
        leaseOwner: 'task0039-provider-pg-worker',
        leaseToken: 'task0039-provider-pg-token',
        at: new DateTimeImmutable('2026-07-15T13:30:10+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'capability_unsupported');

    expect(DB::table('campaign_schedules')->where('id', $schedule->id)->value('resolved_at_utc'))
        ->not->toBeNull()
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(0)
        ->and(DB::table('outbox_messages')
            ->where('topic', 'publishing.campaign_schedule.execution_intent.ready')
            ->count())->toBe(0);
});
