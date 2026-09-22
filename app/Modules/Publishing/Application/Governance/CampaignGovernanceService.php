<?php

namespace App\Modules\Publishing\Application\Governance;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalEvaluation;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleRuleSet;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleStrategy;
use App\Modules\Publishing\Domain\Scheduling\LocalScheduleTimeResolver;
use App\Modules\Publishing\Domain\Scheduling\QueueNextSlotResolver;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRuleRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignGovernanceService
{
    public function __construct(
        private DatabaseCampaignRepository $campaigns,
        private CampaignApprovalEvaluator $approvals,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
        private LocalScheduleTimeResolver $scheduleTimeResolver,
        private DatabaseCampaignScheduleRuleRepository $scheduleRules,
        private QueueNextSlotResolver $queueSlotResolver,
    ) {}

    public function requestApproval(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $eventId,
        string $eventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_CREATE);

        $campaign = $this->requireCampaign($context, $campaignId);
        if ($campaign->status !== CampaignStatus::Review) {
            throw new InvalidArgumentException('Campaign must be in review before approval can be requested.');
        }

        $snapshot = $this->requireLatestSnapshot($context, $campaign, $snapshotId);
        $this->assertSnapshotAuthority($snapshot, $at);

        $next = $campaign->transitionTo(CampaignStatus::NeedsApproval, $at);
        $event = CampaignEvent::transitioned(
            before: $campaign,
            after: $next,
            id: $eventId,
            actorId: $context->actorId,
            reason: $reason,
            evidence: [
                'snapshot_id' => $snapshot->id,
                'snapshot_hash' => $snapshot->snapshotHash,
                'target_set_hash' => $snapshot->targetSetHash,
            ],
            idempotencyKey: $eventIdempotencyKey,
            occurredAt: $at,
        );

        return $this->campaigns->transitionCampaign($next, $campaign->stateVersion, $event);
    }

    public function approve(
        User $approver,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $roleKey,
        string $decisionId,
        string $decisionIdempotencyKey,
        string $approvalEventId,
        string $approvalEventIdempotencyKey,
        string $transitionEventId,
        string $transitionEventIdempotencyKey,
        ?string $reason,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertApprovalAuthority($approver, $context, $roleKey);

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $snapshotId,
            $roleKey,
            $decisionId,
            $decisionIdempotencyKey,
            $approvalEventId,
            $approvalEventIdempotencyKey,
            $transitionEventId,
            $transitionEventIdempotencyKey,
            $reason,
            $expiresAt,
            $at,
        ): Campaign {
            $campaign = $this->requireCampaign($context, $campaignId);
            if ($campaign->status !== CampaignStatus::NeedsApproval) {
                throw new InvalidArgumentException('Campaign approval requires needs_approval lifecycle state.');
            }

            $snapshot = $this->requireLatestSnapshot($context, $campaign, $snapshotId);
            $this->assertSnapshotAuthority($snapshot, $at);

            $decision = new CampaignApprovalDecision(
                id: $decisionId,
                workspaceId: $context->workspaceId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                targetSetHash: $snapshot->targetSetHash,
                outcome: CampaignApprovalOutcome::Approved,
                actorId: $context->actorId,
                actorRole: $roleKey,
                reason: $reason,
                capabilityEvidenceIds: $snapshot->capabilityEvidenceIds,
                supersedesDecisionId: null,
                expiresAt: $expiresAt,
                idempotencyKey: $decisionIdempotencyKey,
                occurredAt: $at,
            );
            $approvalEvent = CampaignEvent::approvalRecorded(
                decision: $decision,
                id: $approvalEventId,
                evidence: [
                    'snapshot_hash' => $snapshot->snapshotHash,
                    'target_set_hash' => $snapshot->targetSetHash,
                    'capability_evidence_ids' => $snapshot->capabilityEvidenceIds,
                ],
                idempotencyKey: $approvalEventIdempotencyKey,
            );

            $this->campaigns->appendApproval($decision, $approvalEvent);

            $next = $campaign->transitionTo(CampaignStatus::Approved, $at);
            $transitionEvent = CampaignEvent::transitioned(
                before: $campaign,
                after: $next,
                id: $transitionEventId,
                actorId: $context->actorId,
                reason: $reason,
                evidence: [
                    'approval_id' => $decision->id,
                    'snapshot_id' => $snapshot->id,
                    'snapshot_hash' => $snapshot->snapshotHash,
                    'target_set_hash' => $snapshot->targetSetHash,
                ],
                idempotencyKey: $transitionEventIdempotencyKey,
                occurredAt: $at,
            );

            return $this->campaigns->transitionCampaign(
                $next,
                $campaign->stateVersion,
                $transitionEvent,
            );
        });
    }

    public function reject(
        User $approver,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $roleKey,
        string $decisionId,
        string $decisionIdempotencyKey,
        string $approvalEventId,
        string $approvalEventIdempotencyKey,
        string $transitionEventId,
        string $transitionEventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertApprovalAuthority($approver, $context, $roleKey);

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $snapshotId,
            $roleKey,
            $decisionId,
            $decisionIdempotencyKey,
            $approvalEventId,
            $approvalEventIdempotencyKey,
            $transitionEventId,
            $transitionEventIdempotencyKey,
            $reason,
            $at,
        ): Campaign {
            $campaign = $this->requireCampaign($context, $campaignId);
            if ($campaign->status !== CampaignStatus::NeedsApproval) {
                throw new InvalidArgumentException('Campaign rejection requires needs_approval lifecycle state.');
            }

            $snapshot = $this->requireLatestSnapshot($context, $campaign, $snapshotId);

            $decision = new CampaignApprovalDecision(
                id: $decisionId,
                workspaceId: $context->workspaceId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                targetSetHash: $snapshot->targetSetHash,
                outcome: CampaignApprovalOutcome::Rejected,
                actorId: $context->actorId,
                actorRole: $roleKey,
                reason: $reason,
                capabilityEvidenceIds: $snapshot->capabilityEvidenceIds,
                supersedesDecisionId: null,
                expiresAt: null,
                idempotencyKey: $decisionIdempotencyKey,
                occurredAt: $at,
            );
            $approvalEvent = CampaignEvent::approvalRecorded(
                decision: $decision,
                id: $approvalEventId,
                evidence: [
                    'snapshot_hash' => $snapshot->snapshotHash,
                    'target_set_hash' => $snapshot->targetSetHash,
                ],
                idempotencyKey: $approvalEventIdempotencyKey,
            );

            $this->campaigns->appendApproval($decision, $approvalEvent);

            $next = $campaign->transitionTo(CampaignStatus::Draft, $at);
            $transitionEvent = CampaignEvent::transitioned(
                before: $campaign,
                after: $next,
                id: $transitionEventId,
                actorId: $context->actorId,
                reason: $reason,
                evidence: [
                    'approval_id' => $decision->id,
                    'snapshot_id' => $snapshot->id,
                    'outcome' => CampaignApprovalOutcome::Rejected->value,
                ],
                idempotencyKey: $transitionEventIdempotencyKey,
                occurredAt: $at,
            );

            return $this->campaigns->transitionCampaign(
                $next,
                $campaign->stateVersion,
                $transitionEvent,
            );
        });
    }

    public function revokeApproval(
        User $approver,
        TenantContext $context,
        string $campaignId,
        string $roleKey,
        string $decisionId,
        string $decisionIdempotencyKey,
        string $approvalEventId,
        string $approvalEventIdempotencyKey,
        string $transitionEventId,
        string $transitionEventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertApprovalAuthority($approver, $context, $roleKey);

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $roleKey,
            $decisionId,
            $decisionIdempotencyKey,
            $approvalEventId,
            $approvalEventIdempotencyKey,
            $transitionEventId,
            $transitionEventIdempotencyKey,
            $reason,
            $at,
        ): Campaign {
            $campaign = $this->requireCampaign($context, $campaignId);
            if (! in_array($campaign->status, [
                CampaignStatus::Approved,
                CampaignStatus::Ready,
                CampaignStatus::ScheduledIntent,
            ], true)) {
                throw new InvalidArgumentException('Campaign approval revocation requires approved or execution-intent state.');
            }

            $snapshot = $this->requireLatestSnapshot($context, $campaign);
            $decisions = $this->campaigns->approvalDecisions(
                $context->workspaceId,
                $campaign->id,
                $snapshot->id,
            );
            $active = $decisions === [] ? null : $decisions[array_key_last($decisions)];

            if ($active === null || $active->outcome !== CampaignApprovalOutcome::Approved) {
                throw new InvalidArgumentException('Campaign does not have an active approval to revoke.');
            }

            $decision = new CampaignApprovalDecision(
                id: $decisionId,
                workspaceId: $context->workspaceId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                targetSetHash: $snapshot->targetSetHash,
                outcome: CampaignApprovalOutcome::Revoked,
                actorId: $context->actorId,
                actorRole: $roleKey,
                reason: $reason,
                capabilityEvidenceIds: $snapshot->capabilityEvidenceIds,
                supersedesDecisionId: $active->id,
                expiresAt: null,
                idempotencyKey: $decisionIdempotencyKey,
                occurredAt: $at,
            );
            $approvalEvent = CampaignEvent::approvalRecorded(
                decision: $decision,
                id: $approvalEventId,
                evidence: [
                    'supersedes_approval_id' => $active->id,
                    'snapshot_hash' => $snapshot->snapshotHash,
                    'target_set_hash' => $snapshot->targetSetHash,
                ],
                idempotencyKey: $approvalEventIdempotencyKey,
            );

            $this->campaigns->appendApproval($decision, $approvalEvent);

            $next = $campaign->transitionTo(CampaignStatus::NeedsApproval, $at);
            $transitionEvent = CampaignEvent::transitioned(
                before: $campaign,
                after: $next,
                id: $transitionEventId,
                actorId: $context->actorId,
                reason: $reason,
                evidence: [
                    'approval_id' => $decision->id,
                    'superseded_approval_id' => $active->id,
                    'snapshot_id' => $snapshot->id,
                    'invalidation_reason' => 'approval_revoked',
                ],
                idempotencyKey: $transitionEventIdempotencyKey,
                occurredAt: $at,
            );

            return $this->campaigns->transitionCampaign(
                $next,
                $campaign->stateVersion,
                $transitionEvent,
            );
        });
    }

    public function markReady(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $eventId,
        string $eventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_SEND);

        $campaign = $this->requireCampaign($context, $campaignId);
        if ($campaign->status !== CampaignStatus::Approved) {
            throw new InvalidArgumentException('Campaign must be approved before it can become ready.');
        }

        $snapshot = $this->requireLatestSnapshot($context, $campaign);
        $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
        $this->assertEffectiveApproval($evaluation);

        $next = $campaign->transitionTo(CampaignStatus::Ready, $at);
        $event = CampaignEvent::transitioned(
            before: $campaign,
            after: $next,
            id: $eventId,
            actorId: $context->actorId,
            reason: $reason,
            evidence: [
                'approval_id' => $evaluation->decisionId,
                'snapshot_id' => $snapshot->id,
                'snapshot_hash' => $snapshot->snapshotHash,
                'target_set_hash' => $snapshot->targetSetHash,
            ],
            idempotencyKey: $eventIdempotencyKey,
            occurredAt: $at,
        );

        return $this->campaigns->transitionCampaign($next, $campaign->stateVersion, $event);
    }

    public function scheduleIntent(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $eventId,
        string $eventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_SEND);

        $campaign = $this->requireCampaign($context, $campaignId);
        if (! in_array($campaign->status, [CampaignStatus::Approved, CampaignStatus::ScheduledIntent], true)) {
            throw new InvalidArgumentException('Campaign scheduling intent requires approved lifecycle state.');
        }

        $snapshot = $this->requireLatestSnapshot($context, $campaign);

        if ($campaign->status === CampaignStatus::ScheduledIntent) {
            $this->assertScheduledIntentReplay(
                context: $context,
                campaign: $campaign,
                snapshot: $snapshot,
                eventId: $eventId,
                eventIdempotencyKey: $eventIdempotencyKey,
                reason: $reason,
            );

            return $campaign;
        }

        $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
        $this->assertEffectiveApproval($evaluation);
        $this->assertSchedulableIntendedExecution($context, $snapshot, $at);

        $evidence = $this->scheduledIntentEvidence($snapshot, $evaluation);

        $next = $campaign->transitionTo(CampaignStatus::ScheduledIntent, $at);
        $event = CampaignEvent::transitioned(
            before: $campaign,
            after: $next,
            id: $eventId,
            actorId: $context->actorId,
            reason: $reason,
            evidence: $evidence,
            idempotencyKey: $eventIdempotencyKey,
            occurredAt: $at,
        );

        return $this->campaigns->transitionCampaign($next, $campaign->stateVersion, $event);
    }

    public function cancel(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $eventId,
        string $eventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $campaign = $this->requireCampaign($context, $campaignId);

        if (in_array($campaign->status, [
            CampaignStatus::Approved,
            CampaignStatus::Ready,
            CampaignStatus::ScheduledIntent,
        ], true)) {
            $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_SEND);
        } else {
            $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_CREATE);
        }

        if ($campaign->status->isTerminal()) {
            throw new InvalidArgumentException('Terminal campaigns cannot be cancelled again.');
        }

        $snapshot = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
        $next = $campaign->transitionTo(CampaignStatus::Cancelled, $at);
        $event = CampaignEvent::transitioned(
            before: $campaign,
            after: $next,
            id: $eventId,
            actorId: $context->actorId,
            reason: $reason,
            evidence: [
                'decision' => 'cancelled',
                'previous_state_version' => $campaign->stateVersion,
                'snapshot_id' => $snapshot?->id,
                'snapshot_hash' => $snapshot?->snapshotHash,
                'target_set_hash' => $snapshot?->targetSetHash,
            ],
            idempotencyKey: $eventIdempotencyKey,
            occurredAt: $at,
        );

        return $this->campaigns->transitionCampaign($next, $campaign->stateVersion, $event);
    }

    public function complete(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $eventId,
        string $eventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_SEND);

        $campaign = $this->requireCampaign($context, $campaignId);
        if (! in_array($campaign->status, [CampaignStatus::Ready, CampaignStatus::ScheduledIntent], true)) {
            throw new InvalidArgumentException('Campaign completion requires ready or scheduled_intent lifecycle state.');
        }

        $snapshot = $this->requireLatestSnapshot($context, $campaign);
        $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
        $this->assertEffectiveApproval($evaluation);

        $next = $campaign->transitionTo(CampaignStatus::Completed, $at);
        $event = CampaignEvent::transitioned(
            before: $campaign,
            after: $next,
            id: $eventId,
            actorId: $context->actorId,
            reason: $reason,
            evidence: [
                'decision' => 'completed',
                'previous_state_version' => $campaign->stateVersion,
                'approval_id' => $evaluation->decisionId,
                'snapshot_id' => $snapshot->id,
                'snapshot_hash' => $snapshot->snapshotHash,
                'target_set_hash' => $snapshot->targetSetHash,
            ],
            idempotencyKey: $eventIdempotencyKey,
            occurredAt: $at,
        );

        return $this->campaigns->transitionCampaign($next, $campaign->stateVersion, $event);
    }

    public function appendMaterialRevision(
        User $actor,
        TenantContext $context,
        CampaignSnapshot $snapshot,
        string $snapshotEventId,
        string $snapshotEventIdempotencyKey,
        string $invalidationEventId,
        string $invalidationEventIdempotencyKey,
        ?string $reason,
        DateTimeImmutable $at,
    ): CampaignSnapshot {
        $this->assertPermission($actor, $context, PermissionCatalog::CAMPAIGN_CREATE);

        if ($snapshot->workspaceId !== $context->workspaceId) {
            throw new AuthorizationException('Campaign snapshot workspace access denied.');
        }

        return $this->database->connection()->transaction(function () use (
            $context,
            $snapshot,
            $snapshotEventId,
            $snapshotEventIdempotencyKey,
            $invalidationEventId,
            $invalidationEventIdempotencyKey,
            $reason,
            $at,
        ): CampaignSnapshot {
            $campaign = $this->requireCampaign($context, $snapshot->campaignId);
            if ($campaign->status->isTerminal()) {
                throw new InvalidArgumentException('Terminal campaigns cannot create a new approval snapshot.');
            }

            $latest = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
            $previous = $latest;

            if ($latest !== null && $latest->id === $snapshot->id) {
                $previous = $snapshot->parentSnapshotId === null
                    ? null
                    : $this->campaigns->findSnapshot($context->workspaceId, $snapshot->parentSnapshotId);
            } elseif ($latest !== null && $snapshot->parentSnapshotId !== $latest->id) {
                throw new InvalidArgumentException('Campaign material revision must fork from the latest canonical snapshot.');
            }

            $targetSetChanged = $previous !== null
                && ! hash_equals($previous->targetSetHash, $snapshot->targetSetHash);
            $contentVersionChanged = $previous !== null
                && $previous->contentVersionId !== $snapshot->contentVersionId;

            $snapshotEvent = CampaignEvent::snapshotCreated(
                snapshot: $snapshot,
                id: $snapshotEventId,
                actorId: $context->actorId,
                reason: $reason,
                evidence: [
                    'revision_kind' => 'material',
                    'parent_snapshot_id' => $previous?->id,
                    'previous_snapshot_hash' => $previous?->snapshotHash,
                    'previous_target_set_hash' => $previous?->targetSetHash,
                    'snapshot_hash' => $snapshot->snapshotHash,
                    'target_set_hash' => $snapshot->targetSetHash,
                    'target_set_changed' => $targetSetChanged,
                    'content_version_changed' => $contentVersionChanged,
                ],
                idempotencyKey: $snapshotEventIdempotencyKey,
                occurredAt: $at,
            );
            $persisted = $this->campaigns->appendSnapshot($snapshot, $snapshotEvent);

            if (! in_array($campaign->status, [
                CampaignStatus::Approved,
                CampaignStatus::Ready,
                CampaignStatus::ScheduledIntent,
            ], true)) {
                return $persisted;
            }

            $next = $campaign->transitionTo(CampaignStatus::NeedsApproval, $at);
            $invalidation = CampaignEvent::transitioned(
                before: $campaign,
                after: $next,
                id: $invalidationEventId,
                actorId: $context->actorId,
                reason: $reason ?? 'Material campaign revision requires approval re-evaluation.',
                evidence: [
                    'invalidation_reason' => 'material_revision',
                    'previous_snapshot_id' => $previous?->id,
                    'previous_snapshot_hash' => $previous?->snapshotHash,
                    'previous_target_set_hash' => $previous?->targetSetHash,
                    'new_snapshot_id' => $persisted->id,
                    'new_snapshot_hash' => $persisted->snapshotHash,
                    'new_target_set_hash' => $persisted->targetSetHash,
                    'target_set_changed' => $targetSetChanged,
                    'content_version_changed' => $contentVersionChanged,
                ],
                idempotencyKey: $invalidationEventIdempotencyKey,
                occurredAt: $at,
            );

            $this->campaigns->transitionCampaign(
                $next,
                $campaign->stateVersion,
                $invalidation,
            );

            return $persisted;
        });
    }

    public function reconcileApproval(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $roleKey,
        string $revocationDecisionId,
        string $revocationDecisionIdempotencyKey,
        string $revocationEventId,
        string $revocationEventIdempotencyKey,
        string $transitionEventId,
        string $transitionEventIdempotencyKey,
        DateTimeImmutable $at,
    ): CampaignApprovalEvaluation {
        $this->assertApprovalAuthority($actor, $context, $roleKey);

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $roleKey,
            $revocationDecisionId,
            $revocationDecisionIdempotencyKey,
            $revocationEventId,
            $revocationEventIdempotencyKey,
            $transitionEventId,
            $transitionEventIdempotencyKey,
            $at,
        ): CampaignApprovalEvaluation {
            $campaign = $this->requireCampaign($context, $campaignId);
            $snapshot = $this->requireLatestSnapshot($context, $campaign);
            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);

            if (
                $evaluation->valid
                || ! in_array($campaign->status, [
                    CampaignStatus::Approved,
                    CampaignStatus::Ready,
                    CampaignStatus::ScheduledIntent,
                ], true)
            ) {
                return $evaluation;
            }

            $decisions = $this->campaigns->approvalDecisions(
                $context->workspaceId,
                $campaign->id,
                $snapshot->id,
            );
            $active = $decisions === [] ? null : $decisions[array_key_last($decisions)];
            $revocation = null;

            if (
                $active !== null
                && $active->outcome === CampaignApprovalOutcome::Approved
                && $evaluation->decisionId === $active->id
            ) {
                $revocation = new CampaignApprovalDecision(
                    id: $revocationDecisionId,
                    workspaceId: $context->workspaceId,
                    campaignId: $campaign->id,
                    snapshotId: $snapshot->id,
                    targetSetHash: $snapshot->targetSetHash,
                    outcome: CampaignApprovalOutcome::Revoked,
                    actorId: $context->actorId,
                    actorRole: $roleKey,
                    reason: 'Campaign approval invalidated: '.$evaluation->reason->value.'.',
                    capabilityEvidenceIds: $snapshot->capabilityEvidenceIds,
                    supersedesDecisionId: $active->id,
                    expiresAt: null,
                    idempotencyKey: $revocationDecisionIdempotencyKey,
                    occurredAt: $at,
                );
                $revocationEvent = CampaignEvent::approvalRecorded(
                    decision: $revocation,
                    id: $revocationEventId,
                    evidence: [
                        'supersedes_approval_id' => $active->id,
                        'snapshot_hash' => $snapshot->snapshotHash,
                        'target_set_hash' => $snapshot->targetSetHash,
                        'invalidation_reason' => $evaluation->reason->value,
                        'invalidation_detail' => $evaluation->detail,
                    ],
                    idempotencyKey: $revocationEventIdempotencyKey,
                );

                $this->campaigns->appendApproval($revocation, $revocationEvent);
            }

            $next = $campaign->transitionTo(CampaignStatus::NeedsApproval, $at);
            $transitionEvent = CampaignEvent::transitioned(
                before: $campaign,
                after: $next,
                id: $transitionEventId,
                actorId: $context->actorId,
                reason: 'Campaign approval is no longer effective.',
                evidence: [
                    'approval_id' => $evaluation->decisionId,
                    'revocation_decision_id' => $revocation?->id,
                    'snapshot_id' => $snapshot->id,
                    'invalidation_reason' => $evaluation->reason?->value,
                    'invalidation_detail' => $evaluation->detail,
                ],
                idempotencyKey: $transitionEventIdempotencyKey,
                occurredAt: $at,
            );

            $this->campaigns->transitionCampaign($next, $campaign->stateVersion, $transitionEvent);

            return $evaluation;
        });
    }

    /** @return array<string, mixed> */
    private function scheduledIntentEvidence(
        CampaignSnapshot $snapshot,
        CampaignApprovalEvaluation $evaluation,
    ): array {
        return [
            'intent_kind' => 'scheduled',
            'approval_id' => $evaluation->decisionId,
            'snapshot_id' => $snapshot->id,
            'snapshot_hash' => $snapshot->snapshotHash,
            'target_set_hash' => $snapshot->targetSetHash,
            'intended_execution' => $snapshot->intendedExecution,
            'scheduler_execution' => false,
        ];
    }

    private function assertSchedulableIntendedExecution(
        TenantContext $context,
        CampaignSnapshot $snapshot,
        DateTimeImmutable $at,
    ): void {
        $execution = $snapshot->intendedExecution;
        $mode = $execution['mode'] ?? null;

        if ($mode === CampaignScheduleStrategy::FixedInstant->value) {
            $timezone = $execution['timezone'] ?? null;
            $scheduledAtValue = $execution['at'] ?? null;

            if (
                ! is_string($timezone)
                || trim($timezone) === ''
                || ! is_string($scheduledAtValue)
                || trim($scheduledAtValue) === ''
            ) {
                throw new InvalidArgumentException('Campaign scheduled intent must pin timezone and at.');
            }

            try {
                $scheduledAt = $this->scheduleTimeResolver->resolve($timezone, $scheduledAtValue);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(
                    'Campaign scheduled intent must contain a valid IANA timezone and unambiguous local wall-clock time.',
                    previous: $exception,
                );
            }

            if ($scheduledAt <= $at) {
                throw new InvalidArgumentException('Campaign scheduled intent time must be in the future.');
            }

            return;
        }

        if ($mode === CampaignScheduleStrategy::QueueNextSlot->value) {
            $ruleSetId = $execution['rule_set_id'] ?? null;
            $channel = $execution['channel'] ?? null;

            if (
                ! is_string($ruleSetId)
                || trim($ruleSetId) === ''
                || ! is_string($channel)
                || trim($channel) === ''
            ) {
                throw new InvalidArgumentException(
                    'Campaign queue scheduled intent must pin rule_set_id and channel.',
                );
            }

            $ruleSet = $this->scheduleRules->find($context->workspaceId, $ruleSetId);
            if (! $ruleSet instanceof CampaignScheduleRuleSet || $ruleSet->channel !== $channel) {
                throw new InvalidArgumentException(
                    'Campaign queue scheduled intent rule set does not match workspace/channel authority.',
                );
            }

            $resolved = $this->queueSlotResolver->resolve($ruleSet, $at);
            if ($resolved->resolvedAtUtc <= $at) {
                throw new InvalidArgumentException(
                    'Campaign queue scheduled intent must resolve to a future UTC occurrence.',
                );
            }

            return;
        }

        throw new InvalidArgumentException(
            'Campaign scheduled intent requires fixed_instant or queue_next_slot intended execution.',
        );
    }

    private function assertScheduledIntentReplay(
        TenantContext $context,
        Campaign $campaign,
        CampaignSnapshot $snapshot,
        string $eventId,
        string $eventIdempotencyKey,
        ?string $reason,
    ): void {
        $matches = array_values(array_filter(
            $this->campaigns->history($context->workspaceId, $campaign->id),
            static fn (CampaignEvent $event): bool => $event->idempotencyKey === $eventIdempotencyKey,
        ));

        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Campaign scheduled-intent replay does not match canonical history.');
        }

        $event = $matches[0];
        $approvalId = $event->evidence['approval_id'] ?? null;
        $intendedExecution = $event->evidence['intended_execution'] ?? null;

        if (
            $event->id !== $eventId
            || $event->type !== CampaignEvent::LIFECYCLE_TRANSITIONED
            || $event->actorId !== $context->actorId
            || $event->reason !== $reason
            || $event->fromStatus !== CampaignStatus::Approved
            || $event->toStatus !== CampaignStatus::ScheduledIntent
            || ($event->evidence['intent_kind'] ?? null) !== 'scheduled'
            || ($event->evidence['snapshot_id'] ?? null) !== $snapshot->id
            || ($event->evidence['snapshot_hash'] ?? null) !== $snapshot->snapshotHash
            || ($event->evidence['target_set_hash'] ?? null) !== $snapshot->targetSetHash
            || $intendedExecution !== $snapshot->intendedExecution
            || ($event->evidence['scheduler_execution'] ?? null) !== false
            || ! is_string($approvalId)
            || trim($approvalId) === ''
        ) {
            throw new InvalidArgumentException('Campaign scheduled-intent replay conflicts with existing history.');
        }

        $approvalExists = false;
        foreach ($this->campaigns->approvalDecisions(
            $context->workspaceId,
            $campaign->id,
            $snapshot->id,
        ) as $decision) {
            if (
                $decision->id === $approvalId
                && $decision->outcome === CampaignApprovalOutcome::Approved
            ) {
                $approvalExists = true;

                break;
            }
        }

        if (! $approvalExists) {
            throw new InvalidArgumentException('Campaign scheduled-intent replay approval provenance is invalid.');
        }
    }

    private function requireCampaign(TenantContext $context, string $campaignId): Campaign
    {
        $campaign = $this->campaigns->findCampaign($context->workspaceId, $campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campaign does not exist in this workspace.');
        }

        return $campaign;
    }

    private function requireLatestSnapshot(
        TenantContext $context,
        Campaign $campaign,
        ?string $snapshotId = null,
    ): CampaignSnapshot {
        $latest = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);

        if ($latest === null) {
            throw new InvalidArgumentException('Campaign does not have an approval-eligible snapshot.');
        }

        if ($snapshotId !== null && $latest->id !== $snapshotId) {
            throw new InvalidArgumentException('Campaign approval must bind to the latest canonical snapshot.');
        }

        return $latest;
    }

    private function assertSnapshotAuthority(CampaignSnapshot $snapshot, DateTimeImmutable $at): void
    {
        $evaluation = $this->approvals->evaluateSnapshotAuthority($snapshot, $at);

        if (! $evaluation->valid) {
            $reason = $evaluation->reason->value;

            throw new InvalidArgumentException("Campaign snapshot authority is not current: {$reason}.");
        }
    }

    private function assertEffectiveApproval(CampaignApprovalEvaluation $evaluation): void
    {
        if (! $evaluation->valid) {
            $reason = $evaluation->reason->value;

            throw new InvalidArgumentException("Campaign approval is not effective: {$reason}.");
        }
    }

    private function assertApprovalAuthority(
        User $user,
        TenantContext $context,
        string $roleKey,
    ): void {
        $this->assertPermission($user, $context, PermissionCatalog::CAMPAIGN_APPROVE);

        $roleAuthorized = $this->database->connection()->table('workspace_memberships')
            ->join(
                'workspace_membership_roles',
                'workspace_membership_roles.workspace_membership_id',
                '=',
                'workspace_memberships.id',
            )
            ->join('workspace_roles', function ($join): void {
                $join->on('workspace_roles.id', '=', 'workspace_membership_roles.workspace_role_id')
                    ->on('workspace_roles.workspace_id', '=', 'workspace_memberships.workspace_id');
            })
            ->join(
                'workspace_role_permissions',
                'workspace_role_permissions.workspace_role_id',
                '=',
                'workspace_roles.id',
            )
            ->where('workspace_memberships.workspace_id', $context->workspaceId)
            ->where('workspace_memberships.user_id', $user->getKey())
            ->where('workspace_roles.key', $roleKey)
            ->where('workspace_role_permissions.permission', PermissionCatalog::CAMPAIGN_APPROVE)
            ->exists();

        if (! $roleAuthorized) {
            throw new AuthorizationException('Campaign approval role is not currently authorized.');
        }
    }

    private function assertPermission(
        User $user,
        TenantContext $context,
        string $permission,
    ): void {
        if (! $this->authorizer->allows($user, $context, $permission)) {
            throw new AuthorizationException("Campaign permission denied: {$permission}.");
        }
    }
}
