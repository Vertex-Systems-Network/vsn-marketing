<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Governance\CampaignGovernanceService;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

final readonly class CampaignBulkApprovalService
{
    public function __construct(
        private DatabaseManager $database,
        private DatabaseCampaignRepository $campaigns,
        private CampaignGovernanceService $governance,
        private CampaignApproverRoleResolver $roles,
    ) {}

    /**
     * @param list<array{campaign_id: string, snapshot_id: string, state_version: int}> $items
     * @return array<string, mixed>
     */
    public function handle(
        User $actor,
        TenantContext $context,
        string $operation,
        array $items,
        bool $confirmed,
        string $batchId,
        ?string $reason,
        DateTimeImmutable $at,
    ): array {
        if (! in_array($operation, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Unsupported campaign approval operation.');
        }

        if ($items === [] || count($items) > 25) {
            throw new InvalidArgumentException('Bulk approval requires between 1 and 25 campaigns.');
        }

        $roleKey = $this->roles->resolve($actor, $context);
        $seen = [];
        $results = [];

        foreach ($items as $item) {
            $campaignId = $item['campaign_id'];
            if (isset($seen[$campaignId])) {
                $results[] = $this->result($item, 'ineligible', 'duplicate_campaign');

                continue;
            }
            $seen[$campaignId] = true;

            $results[] = $confirmed
                ? $this->executeOne($actor, $context, $roleKey, $operation, $item, $batchId, $reason, $at)
                : $this->preflightOne($context, $operation, $item, $batchId);
        }

        $counts = [
            'total' => count($results),
            'eligible' => 0,
            'applied' => 0,
            'already_applied' => 0,
            'ineligible' => 0,
            'conflict' => 0,
        ];

        foreach ($results as $result) {
            $status = $result['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return [
            'batch_id' => $batchId,
            'operation' => $operation,
            'confirmed' => $confirmed,
            'role_source' => 'server_resolved_workspace_authority',
            'counts' => $counts,
            'results' => $results,
        ];
    }

    /**
     * @param array{campaign_id: string, snapshot_id: string, state_version: int} $item
     * @return array<string, mixed>
     */
    private function preflightOne(
        TenantContext $context,
        string $operation,
        array $item,
        string $batchId,
    ): array {
        $replay = $this->existingDecision($context, $operation, $item['campaign_id'], $batchId);
        if ($replay !== null) {
            return $this->result($item, 'already_applied', 'idempotent_replay');
        }

        $campaign = $this->campaigns->findCampaign($context->workspaceId, $item['campaign_id']);
        if ($campaign === null) {
            return $this->result($item, 'ineligible', 'campaign_not_found');
        }

        if ($campaign->stateVersion !== $item['state_version']) {
            return $this->result($item, 'conflict', 'stale_state_version');
        }

        if ($campaign->status !== CampaignStatus::NeedsApproval) {
            return $this->result($item, 'ineligible', 'campaign_not_awaiting_approval');
        }

        $snapshot = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
        if ($snapshot === null || $snapshot->id !== $item['snapshot_id']) {
            return $this->result($item, 'conflict', 'stale_snapshot');
        }

        return $this->result($item, 'eligible', null);
    }

    /**
     * @param array{campaign_id: string, snapshot_id: string, state_version: int} $item
     * @return array<string, mixed>
     */
    private function executeOne(
        User $actor,
        TenantContext $context,
        string $roleKey,
        string $operation,
        array $item,
        string $batchId,
        ?string $reason,
        DateTimeImmutable $at,
    ): array {
        try {
            return $this->database->connection()->transaction(function () use (
                $actor,
                $context,
                $roleKey,
                $operation,
                $item,
                $batchId,
                $reason,
                $at,
            ): array {
                $replay = $this->existingDecision($context, $operation, $item['campaign_id'], $batchId);
                if ($replay !== null) {
                    return $this->result($item, 'already_applied', 'idempotent_replay');
                }

                $campaign = $this->campaigns->lockCampaignForUpdate($context->workspaceId, $item['campaign_id']);
                if ($campaign->stateVersion !== $item['state_version']) {
                    return $this->result($item, 'conflict', 'stale_state_version');
                }

                if ($campaign->status !== CampaignStatus::NeedsApproval) {
                    return $this->result($item, 'ineligible', 'campaign_not_awaiting_approval');
                }

                $snapshot = $this->campaigns->latestSnapshot($context->workspaceId, $campaign->id);
                if ($snapshot === null || $snapshot->id !== $item['snapshot_id']) {
                    return $this->result($item, 'conflict', 'stale_snapshot');
                }

                $ids = $this->identities($batchId, $campaign->id, $operation);

                if ($operation === 'approve') {
                    $this->governance->approve(
                        approver: $actor,
                        context: $context,
                        campaignId: $campaign->id,
                        snapshotId: $snapshot->id,
                        roleKey: $roleKey,
                        decisionId: $ids['decision_id'],
                        decisionIdempotencyKey: $ids['decision_key'],
                        approvalEventId: $ids['approval_event_id'],
                        approvalEventIdempotencyKey: $ids['approval_event_key'],
                        transitionEventId: $ids['transition_event_id'],
                        transitionEventIdempotencyKey: $ids['transition_event_key'],
                        reason: $reason,
                        expiresAt: null,
                        at: $at,
                    );
                } else {
                    $this->governance->reject(
                        approver: $actor,
                        context: $context,
                        campaignId: $campaign->id,
                        snapshotId: $snapshot->id,
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
                }

                return $this->result($item, 'applied', null);
            });
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                return $this->result($item, 'conflict', $this->safeReason($exception));
            }

            throw $exception;
        }
    }

    private function existingDecision(
        TenantContext $context,
        string $operation,
        string $campaignId,
        string $batchId,
    ): ?string {
        $expectedOutcome = $operation === 'approve'
            ? CampaignApprovalOutcome::Approved->value
            : CampaignApprovalOutcome::Rejected->value;
        $decision = $this->database->connection()->table('campaign_approval_decisions')
            ->select(['outcome'])
            ->where('workspace_id', $context->workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('idempotency_key', $this->identities($batchId, $campaignId, $operation)['decision_key'])
            ->first();

        if ($decision === null) {
            return null;
        }

        if ((string) $decision->outcome !== $expectedOutcome) {
            throw new InvalidArgumentException('Bulk approval idempotency key conflicts with canonical history.');
        }

        return $expectedOutcome;
    }

    /**
     * @return array{decision_id: string, decision_key: string, approval_event_id: string, approval_event_key: string, transition_event_id: string, transition_event_key: string}
     */
    private function identities(string $batchId, string $campaignId, string $operation): array
    {
        $prefix = 'operator-bulk:'.$operation.':'.$batchId.':'.$campaignId;

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

    /**
     * @param array{campaign_id: string, snapshot_id: string, state_version: int} $item
     * @return array<string, mixed>
     */
    private function result(array $item, string $status, ?string $reason): array
    {
        return [
            'campaign_id' => $item['campaign_id'],
            'snapshot_id' => $item['snapshot_id'],
            'expected_state_version' => $item['state_version'],
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function safeReason(InvalidArgumentException $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'optimistic concurrency') => 'stale_state_version',
            str_contains($exception->getMessage(), 'latest canonical snapshot') => 'stale_snapshot',
            str_contains($exception->getMessage(), 'snapshot authority') => 'snapshot_authority_invalid',
            str_contains($exception->getMessage(), 'needs_approval') => 'campaign_not_awaiting_approval',
            default => 'canonical_governance_rejected',
        };
    }
}
