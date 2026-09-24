<?php

namespace Tests\Support\Publishing;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class Task0040PublicationFixture
{
    /** @return array<string, mixed> */
    public static function create(string $suffix = 'base'): array
    {
        $organization = Organization::query()->create([
            'name' => 'TASK-0040 '.$suffix,
            'slug' => 'task0040-'.$suffix,
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'TASK-0040 '.$suffix,
            'slug' => 'task0040-workspace-'.$suffix,
        ]);
        $workspaceId = (string) $workspace->getKey();

        $author = User::query()->create([
            'name' => 'TASK-0040 Author '.$suffix,
            'email' => 'task0040-author-'.$suffix.'@example.test',
            'password' => Hash::make('secret-pass'),
        ]);
        $approver = User::query()->create([
            'name' => 'TASK-0040 Approver '.$suffix,
            'email' => 'task0040-approver-'.$suffix.'@example.test',
            'password' => Hash::make('secret-pass'),
        ]);

        $roles = app(WorkspaceRoleManager::class);
        $authorMembership = $roles->addMember($author, $workspaceId);
        $authorRole = $roles->createRole($workspaceId, 'task0040-sender-'.$suffix, 'TASK-0040 sender');
        $roles->grantPermission($authorRole, PermissionCatalog::CAMPAIGN_SEND);
        $roles->assignRole($authorMembership, $authorRole);

        $approverMembership = $roles->addMember($approver, $workspaceId);
        $approverRoleKey = 'task0040-approver-'.$suffix;
        $approverRole = $roles->createRole($workspaceId, $approverRoleKey, 'TASK-0040 approver');
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
            'name' => 'TASK-0040 content '.$suffix,
            'lifecycle' => 'active',
            'created_by_actor_id' => (string) $author->getKey(),
            'audit_provenance' => json_encode(['source' => 'task0040-fixture'], JSON_THROW_ON_ERROR),
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
            'audit_provenance' => json_encode(['source' => 'task0040-fixture'], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'task0040-content-'.$suffix,
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
            'provider_key' => 'task0040-'.$suffix,
            'display_name' => 'TASK-0040 Provider '.$suffix,
            'category' => 'social',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'source_url' => 'https://example.test/task0040/provider',
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
            'secret_reference' => 'vault://task0040/'.$suffix,
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
            'source_url' => 'https://example.test/task0040/connection',
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
            'operation' => 'publication.create',
            'support_status' => 'supported',
            'required_scopes' => json_encode(['publish.write'], JSON_THROW_ON_ERROR),
            'required_roles' => json_encode(['publisher'], JSON_THROW_ON_ERROR),
            'constraints' => json_encode([], JSON_THROW_ON_ERROR),
            'source_url' => 'https://example.test/task0040/capability',
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
            name: 'TASK-0040 Campaign '.$suffix,
            idempotencyKey: 'task0040-campaign-'.$suffix,
            createdByActorId: (string) $author->getKey(),
            createdAt: $createdAt,
        );
        $repository->createCampaign(
            $campaign,
            CampaignEvent::created(
                campaign: $campaign,
                id: (string) Str::uuid(),
                actorId: (string) $author->getKey(),
                reason: 'Create TASK-0040 fixture.',
                evidence: [],
                idempotencyKey: 'task0040-created-'.$suffix,
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
                reason: 'Review TASK-0040 fixture.',
                evidence: [],
                idempotencyKey: 'task0040-review-'.$suffix,
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
            idempotencyKey: 'task0040-snapshot-'.$suffix,
            createdByActorId: (string) $author->getKey(),
            createdAt: new DateTimeImmutable('2026-07-15T10:02:00+00:00'),
        );
        $repository->appendSnapshot(
            $snapshot,
            CampaignEvent::snapshotCreated(
                snapshot: $snapshot,
                id: (string) Str::uuid(),
                actorId: (string) $author->getKey(),
                reason: 'Pin TASK-0040 publication target.',
                evidence: [],
                idempotencyKey: 'task0040-snapshot-event-'.$suffix,
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
                reason: 'Request TASK-0040 approval.',
                evidence: ['snapshot_id' => $snapshot->id],
                idempotencyKey: 'task0040-needs-'.$suffix,
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
            reason: 'Approve TASK-0040 fixture.',
            capabilityEvidenceIds: [$providerCapabilityId],
            supersedesDecisionId: null,
            expiresAt: new DateTimeImmutable('2026-07-15T15:00:00+00:00'),
            idempotencyKey: 'task0040-approval-'.$suffix,
            occurredAt: new DateTimeImmutable('2026-07-15T10:04:00+00:00'),
        );
        $repository->appendApproval(
            $decision,
            CampaignEvent::approvalRecorded(
                decision: $decision,
                id: (string) Str::uuid(),
                evidence: ['snapshot_hash' => $snapshot->snapshotHash],
                idempotencyKey: 'task0040-approval-event-'.$suffix,
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
                reason: 'TASK-0040 approved.',
                evidence: ['approval_id' => $decision->id],
                idempotencyKey: 'task0040-approved-'.$suffix,
                occurredAt: $approvedAt,
            ),
        );

        $scheduledIntent = app(CampaignGovernanceService::class)->scheduleIntent(
            actor: $author,
            context: $context,
            campaignId: $campaign->id,
            eventId: (string) Str::uuid(),
            eventIdempotencyKey: 'task0040-intent-'.$suffix,
            reason: 'Record canonical publication schedule intent.',
            at: new DateTimeImmutable('2026-07-15T11:00:00+00:00'),
        );

        $schedule = app(CampaignCalendarService::class)->scheduleFixedInstant(
            actor: $author,
            context: $context,
            campaignId: $scheduledIntent->id,
            snapshotId: $snapshot->id,
            scheduleId: (string) Str::uuid(),
            idempotencyKey: 'task0040-schedule-'.$suffix,
            at: new DateTimeImmutable('2026-07-15T11:01:00+00:00'),
        );

        $claims = app(CampaignScheduleDueClaimService::class);
        $claims->acquireDueClaim(
            workspaceId: $workspaceId,
            scheduleId: $schedule->id,
            leaseOwner: 'task0040-worker-'.$suffix,
            leaseToken: 'task0040-token-'.$suffix,
            leaseSeconds: 120,
            at: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        );
        $intent = $claims->emitExecutionIntent(
            workspaceId: $workspaceId,
            scheduleId: $schedule->id,
            leaseOwner: 'task0040-worker-'.$suffix,
            leaseToken: 'task0040-token-'.$suffix,
            at: new DateTimeImmutable('2026-07-15T13:30:10+00:00'),
        );

        return [
            'organization' => $organization,
            'workspace' => $workspace,
            'author' => $author,
            'approver' => $approver,
            'context' => $context,
            'campaign' => $scheduledIntent,
            'snapshot' => $snapshot,
            'target' => $target,
            'executionIntent' => $intent,
            'providerId' => $providerId,
            'providerConnectionId' => $providerConnectionId,
            'providerCapabilityId' => $providerCapabilityId,
        ];
    }
}
