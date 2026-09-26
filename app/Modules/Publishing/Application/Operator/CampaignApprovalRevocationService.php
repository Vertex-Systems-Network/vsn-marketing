<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Governance\CampaignGovernanceService;
use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignApprovalRevocationService
{
    public function __construct(
        private DatabaseManager $database,
        private DatabaseCampaignRepository $campaigns,
        private CampaignGovernanceService $governance,
        private CampaignApproverRoleResolver $roles,
    ) {}

    /** @return array<string, mixed> */
    public function preflight(
        User $actor,
        TenantContext $context,
        string $commandId,
        string $campaignId,
        string $snapshotId,
        int $expectedStateVersion,
        string $reason,
        DateTimeImmutable $at,
    ): array {
        $this->assertInputs($commandId, $campaignId, $snapshotId, $expectedStateVersion);
        $reason = $this->normalizeReason($reason);
        $roleKey = $this->roles->resolve($actor, $context);
        $campaign = $this->requireCampaign($context, $campaignId);

        $this->assertExpectedState($campaign, $expectedStateVersion);
        $this->assertRevocableStatus($campaign);

        $snapshot = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
        if ($snapshot === null || $snapshot->id !== $snapshotId) {
            throw new InvalidArgumentException(
                'Campaign approval revocation must bind the exact latest canonical snapshot.',
            );
        }

        $active = $this->requireActiveApproval($context, $campaign->id, $snapshot->id);

        return [
            'status' => 'eligible',
            'confirmation_required' => true,
            'provider_side_effect_executed' => false,
            'role_source' => 'server_resolved_workspace_authority',
            'actor_role' => $roleKey,
            'campaign_id' => $campaign->id,
            'snapshot_id' => $snapshot->id,
            'expected_state_version' => $expectedStateVersion,
            'current_status' => $campaign->status->value,
            'active_approval_id' => $active->id,
            'confirmation_hash' => $this->confirmationHash(
                context: $context,
                commandId: $commandId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                expectedStateVersion: $expectedStateVersion,
                activeApprovalId: $active->id,
                roleKey: $roleKey,
                reason: $reason,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function revoke(
        User $actor,
        TenantContext $context,
        string $commandId,
        string $campaignId,
        string $snapshotId,
        int $expectedStateVersion,
        string $reason,
        string $confirmationHash,
        DateTimeImmutable $at,
    ): array {
        $this->assertInputs($commandId, $campaignId, $snapshotId, $expectedStateVersion);
        CampaignPayloadGuard::assertSha256($confirmationHash, 'approvalRevocation.confirmationHash');
        $reason = $this->normalizeReason($reason);

        $currentRole = $this->roles->resolve($actor, $context);
        $replay = $this->findReplay($context, $campaignId, $snapshotId, $commandId);
        if ($replay instanceof CampaignApprovalDecision) {
            $this->assertReplay(
                decision: $replay,
                context: $context,
                commandId: $commandId,
                campaignId: $campaignId,
                snapshotId: $snapshotId,
                expectedStateVersion: $expectedStateVersion,
                reason: $reason,
                confirmationHash: $confirmationHash,
            );

            $campaign = $this->requireCampaign($context, $campaignId);

            return [
                'status' => 'already_applied',
                'provider_side_effect_executed' => false,
                'role_source' => 'server_resolved_workspace_authority',
                'actor_role' => $currentRole,
                'campaign_id' => $campaignId,
                'snapshot_id' => $snapshotId,
                'expected_state_version' => $expectedStateVersion,
                'current_state_version' => $campaign->stateVersion,
                'current_status' => $campaign->status->value,
                'revocation_decision_id' => $replay->id,
                'superseded_approval_id' => $replay->supersedesDecisionId,
            ];
        }

        $preflight = $this->preflight(
            actor: $actor,
            context: $context,
            commandId: $commandId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            expectedStateVersion: $expectedStateVersion,
            reason: $reason,
            at: $at,
        );

        if (! hash_equals((string) $preflight['confirmation_hash'], $confirmationHash)) {
            throw new InvalidArgumentException(
                'Campaign approval revocation confirmation is stale or does not match current authority.',
            );
        }

        return $this->database->connection()->transaction(function () use (
            $actor,
            $context,
            $commandId,
            $campaignId,
            $snapshotId,
            $expectedStateVersion,
            $reason,
            $confirmationHash,
            $at,
        ): array {
            $roleKey = $this->roles->resolve($actor, $context);
            $campaign = $this->campaigns->lockCampaignForUpdate($context->workspaceId, $campaignId);

            $this->assertExpectedState($campaign, $expectedStateVersion);
            $this->assertRevocableStatus($campaign);

            $snapshot = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
            if ($snapshot === null || $snapshot->id !== $snapshotId) {
                throw new InvalidArgumentException(
                    'Campaign approval revocation must bind the exact latest canonical snapshot.',
                );
            }

            $active = $this->requireActiveApproval($context, $campaign->id, $snapshot->id);
            $lockedHash = $this->confirmationHash(
                context: $context,
                commandId: $commandId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                expectedStateVersion: $expectedStateVersion,
                activeApprovalId: $active->id,
                roleKey: $roleKey,
                reason: $reason,
            );

            if (! hash_equals($lockedHash, $confirmationHash)) {
                throw new InvalidArgumentException(
                    'Campaign approval revocation confirmation is stale or does not match locked canonical authority.',
                );
            }

            $ids = $this->identities($commandId, $campaign->id);
            $revoked = $this->governance->revokeApproval(
                approver: $actor,
                context: $context,
                campaignId: $campaign->id,
                roleKey: $roleKey,
                decisionId: $ids['decision_id'],
                decisionIdempotencyKey: $ids['decision_key'],
                approvalEventId: $ids['approval_event_id'],
                approvalEventIdempotencyKey: $ids['approval_event_key'],
                transitionEventId: $ids['transition_event_id'],
                transitionEventIdempotencyKey: $ids['transition_event_key'],
                reason: $reason,
                at: $at,
            );

            $decision = $this->findReplay($context, $campaign->id, $snapshot->id, $commandId);
            if (! $decision instanceof CampaignApprovalDecision) {
                throw new InvalidArgumentException(
                    'Campaign approval revocation did not produce canonical append-only provenance.',
                );
            }

            return [
                'status' => 'applied',
                'provider_side_effect_executed' => false,
                'role_source' => 'server_resolved_workspace_authority',
                'actor_role' => $roleKey,
                'campaign_id' => $revoked->id,
                'snapshot_id' => $snapshot->id,
                'expected_state_version' => $expectedStateVersion,
                'current_state_version' => $revoked->stateVersion,
                'current_status' => $revoked->status->value,
                'revocation_decision_id' => $decision->id,
                'superseded_approval_id' => $decision->supersedesDecisionId,
            ];
        });
    }

    private function requireCampaign(TenantContext $context, string $campaignId): Campaign
    {
        $campaign = $this->campaigns->findCampaign($context->workspaceId, $campaignId);
        if (! $campaign instanceof Campaign) {
            throw new InvalidArgumentException('Campaign does not exist in this workspace.');
        }

        return $campaign;
    }

    private function assertExpectedState(Campaign $campaign, int $expectedStateVersion): void
    {
        if ($campaign->stateVersion !== $expectedStateVersion) {
            throw new InvalidArgumentException(
                'Campaign approval revocation state_version is stale.',
            );
        }
    }

    private function assertRevocableStatus(Campaign $campaign): void
    {
        if (! in_array($campaign->status, [
            CampaignStatus::Approved,
            CampaignStatus::Ready,
            CampaignStatus::ScheduledIntent,
        ], true)) {
            throw new InvalidArgumentException(
                'Campaign approval revocation requires approved or execution-intent state.',
            );
        }
    }

    private function requireActiveApproval(
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
    ): CampaignApprovalDecision {
        $decisions = $this->campaigns->approvalDecisions(
            $context->workspaceId,
            $campaignId,
            $snapshotId,
        );
        $active = $decisions === [] ? null : $decisions[array_key_last($decisions)];

        if (! $active instanceof CampaignApprovalDecision || $active->outcome !== CampaignApprovalOutcome::Approved) {
            throw new InvalidArgumentException(
                'Campaign does not have an active approval to revoke for the exact snapshot.',
            );
        }

        return $active;
    }

    private function findReplay(
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $commandId,
    ): ?CampaignApprovalDecision {
        $expectedKey = $this->identities($commandId, $campaignId)['decision_key'];

        foreach ($this->campaigns->approvalDecisions(
            $context->workspaceId,
            $campaignId,
            $snapshotId,
        ) as $decision) {
            if ($decision->idempotencyKey === $expectedKey) {
                return $decision;
            }
        }

        return null;
    }

    private function assertReplay(
        CampaignApprovalDecision $decision,
        TenantContext $context,
        string $commandId,
        string $campaignId,
        string $snapshotId,
        int $expectedStateVersion,
        string $reason,
        string $confirmationHash,
    ): void {
        if (
            $decision->outcome !== CampaignApprovalOutcome::Revoked
            || $decision->actorId !== $context->actorId
            || $decision->campaignId !== $campaignId
            || $decision->snapshotId !== $snapshotId
            || $decision->reason !== $reason
            || $decision->supersedesDecisionId === null
        ) {
            throw new InvalidArgumentException(
                'Campaign approval revocation replay conflicts with canonical history.',
            );
        }

        $historicalHash = $this->confirmationHash(
            context: $context,
            commandId: $commandId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            expectedStateVersion: $expectedStateVersion,
            activeApprovalId: $decision->supersedesDecisionId,
            roleKey: $decision->actorRole,
            reason: $reason,
        );

        if (! hash_equals($historicalHash, $confirmationHash)) {
            throw new InvalidArgumentException(
                'Campaign approval revocation replay confirmation conflicts with canonical history.',
            );
        }
    }

    private function assertInputs(
        string $commandId,
        string $campaignId,
        string $snapshotId,
        int $expectedStateVersion,
    ): void {
        CampaignPayloadGuard::assertIdentifier($commandId, 'approvalRevocation.commandId');
        CampaignPayloadGuard::assertIdentifier($campaignId, 'approvalRevocation.campaignId');
        CampaignPayloadGuard::assertIdentifier($snapshotId, 'approvalRevocation.snapshotId');

        if ($expectedStateVersion < 1) {
            throw new InvalidArgumentException(
                'Campaign approval revocation requires a positive expected state_version.',
            );
        }
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(
                'Campaign approval revocation reason must contain between 3 and 500 characters.',
            );
        }

        return $reason;
    }

    private function confirmationHash(
        TenantContext $context,
        string $commandId,
        string $campaignId,
        string $snapshotId,
        int $expectedStateVersion,
        string $activeApprovalId,
        string $roleKey,
        string $reason,
    ): string {
        return CampaignPayloadGuard::hash([
            'operation' => 'campaign.approval.revoke',
            'workspace_id' => $context->workspaceId,
            'actor_id' => $context->actorId,
            'command_id' => $commandId,
            'campaign_id' => $campaignId,
            'snapshot_id' => $snapshotId,
            'expected_state_version' => $expectedStateVersion,
            'active_approval_id' => $activeApprovalId,
            'resolved_role' => $roleKey,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{decision_id: string, decision_key: string, approval_event_id: string, approval_event_key: string, transition_event_id: string, transition_event_key: string}
     */
    private function identities(string $commandId, string $campaignId): array
    {
        $prefix = 'operator-revoke:'.$commandId.':'.$campaignId;

        return [
            'decision_id' => $this->stableUuid($prefix.':decision'),
            'decision_key' => $prefix.':decision',
            'approval_event_id' => $this->stableUuid($prefix.':approval-event'),
            'approval_event_key' => $prefix.':approval-event',
            'transition_event_id' => $this->stableUuid($prefix.':transition-event'),
            'transition_event_key' => $prefix.':transition-event',
        ];
    }

    private function stableUuid(string $seed): string
    {
        $hex = hash('sha256', $seed);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .'5'.substr($hex, 13, 3).'-'
            .'a'.substr($hex, 17, 3).'-'
            .substr($hex, 20, 12);
    }
}
