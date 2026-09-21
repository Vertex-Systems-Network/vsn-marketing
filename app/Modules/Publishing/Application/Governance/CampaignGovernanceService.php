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
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
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

            $previous = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
            if ($previous !== null && $snapshot->parentSnapshotId !== $previous->id) {
                throw new InvalidArgumentException('Campaign material revision must fork from the latest canonical snapshot.');
            }

            $snapshotEvent = CampaignEvent::snapshotCreated(
                snapshot: $snapshot,
                id: $snapshotEventId,
                actorId: $context->actorId,
                reason: $reason,
                evidence: [
                    'parent_snapshot_id' => $previous?->id,
                    'snapshot_hash' => $snapshot->snapshotHash,
                    'target_set_hash' => $snapshot->targetSetHash,
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
                    'new_snapshot_id' => $persisted->id,
                    'new_snapshot_hash' => $persisted->snapshotHash,
                    'new_target_set_hash' => $persisted->targetSetHash,
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
                    reason: 'Campaign approval invalidated: '.($evaluation->reason?->value ?? 'unknown').'.',
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
                        'invalidation_reason' => $evaluation->reason?->value,
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
            $reason = $evaluation->reason?->value ?? 'unknown';

            throw new InvalidArgumentException("Campaign snapshot authority is not current: {$reason}.");
        }
    }

    private function assertEffectiveApproval(CampaignApprovalEvaluation $evaluation): void
    {
        if (! $evaluation->valid) {
            $reason = $evaluation->reason?->value ?? 'unknown';

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

    /** @param list<string> $permissions */
    private function assertAnyPermission(
        User $user,
        TenantContext $context,
        array $permissions,
    ): void {
        foreach ($permissions as $permission) {
            if ($this->authorizer->allows($user, $context, $permission)) {
                return;
            }
        }

        throw new AuthorizationException('Campaign governance permission denied.');
    }
}
