<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Publication\PublicationAggregateService;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcome;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class PublishingOperatorReadModel
{
    public function __construct(
        private DatabaseManager $database,
        private DatabaseCampaignRepository $campaigns,
        private PublicationAggregateService $aggregates,
    ) {}

    /** @return array<string, mixed> */
    public function forWorkspace(TenantContext $context): array
    {
        $connection = $this->database->connection();
        $workspace = $connection->table('workspaces')
            ->select(['id', 'name', 'slug'])
            ->where('id', $context->workspaceId)
            ->first();

        $campaigns = $connection->table('campaigns')
            ->where('workspace_id', $context->workspaceId)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (stdClass $campaign): array => $this->campaign($context, $campaign))
            ->values()
            ->all();

        return [
            'workspace' => [
                'id' => $context->workspaceId,
                'name' => $workspace instanceof stdClass ? (string) $workspace->name : 'Workspace',
                'slug' => $workspace instanceof stdClass ? (string) $workspace->slug : '',
            ],
            'campaigns' => $campaigns,
            'summary' => [
                'campaigns' => count($campaigns),
                'needs_approval' => count(array_filter(
                    $campaigns,
                    static fn (array $campaign): bool => $campaign['status'] === 'needs_approval',
                )),
                'scheduled' => count(array_filter(
                    $campaigns,
                    static fn (array $campaign): bool => $campaign['schedule'] !== null,
                )),
                'partial_success' => count(array_filter(
                    $campaigns,
                    static fn (array $campaign): bool => ($campaign['publication']['state'] ?? null) === 'partial_success',
                )),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function campaign(TenantContext $context, stdClass $campaign): array
    {
        $workspaceId = $context->workspaceId;
        $campaignId = (string) $campaign->id;
        $snapshot = $this->campaigns->latestSnapshot($workspaceId, $campaignId);

        if (! $snapshot instanceof CampaignSnapshot) {
            return [
                'id' => $campaignId,
                'name' => (string) $campaign->name,
                'status' => (string) $campaign->status,
                'state_version' => (int) $campaign->state_version,
                'snapshot' => null,
                'approval' => null,
                'schedule' => null,
                'publication' => null,
            ];
        }

        $approvals = $this->campaigns->approvalDecisions($workspaceId, $campaignId, $snapshot->id);
        $latestApproval = $approvals === [] ? null : $approvals[array_key_last($approvals)];

        $schedule = $this->database->connection()->table('campaign_schedules')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', $snapshot->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $intentId = $this->database->connection()->table('campaign_schedule_execution_intents')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', $snapshot->id)
            ->orderByDesc('emitted_at')
            ->orderByDesc('id')
            ->value('id');

        return [
            'id' => $campaignId,
            'name' => (string) $campaign->name,
            'status' => (string) $campaign->status,
            'state_version' => (int) $campaign->state_version,
            'snapshot' => $this->snapshot($snapshot),
            'approval' => $latestApproval instanceof CampaignApprovalDecision
                ? $this->approval($latestApproval)
                : null,
            'schedule' => $schedule instanceof stdClass ? [
                'strategy' => (string) $schedule->strategy,
                'timezone_id' => (string) $schedule->timezone_id,
                'local_scheduled_at' => (string) $schedule->local_scheduled_at,
                'resolved_at_utc' => (string) $schedule->resolved_at_utc,
            ] : null,
            'publication' => is_string($intentId) && $intentId !== ''
                ? $this->publication($workspaceId, $intentId)
                : $this->notStartedPublication($snapshot),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(CampaignSnapshot $snapshot): array
    {
        $channels = array_values(array_unique(array_map(
            static fn ($target): string => $target->channel,
            $snapshot->targets,
        )));
        sort($channels, SORT_STRING);

        $execution = [];
        foreach (['mode', 'timezone', 'at', 'channel'] as $key) {
            $value = $snapshot->intendedExecution[$key] ?? null;
            if (is_string($value) || is_int($value) || is_bool($value)) {
                $execution[$key] = $value;
            }
        }

        return [
            'id' => $snapshot->id,
            'version_number' => $snapshot->versionNumber,
            'content_version_id' => $snapshot->contentVersionId,
            'snapshot_hash' => $snapshot->snapshotHash,
            'target_set_hash' => $snapshot->targetSetHash,
            'target_count' => count($snapshot->targets),
            'channels' => $channels,
            'intended_execution' => $execution,
            'created_at' => $snapshot->createdAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function approval(CampaignApprovalDecision $approval): array
    {
        return [
            'outcome' => $approval->outcome->value,
            'actor_role' => $approval->actorRole,
            'occurred_at' => $approval->occurredAt->format(DATE_ATOM),
            'expires_at' => $approval->expiresAt?->format(DATE_ATOM),
            'revoked' => $approval->outcome->value === 'revoked',
        ];
    }

    /** @return array<string, mixed> */
    private function publication(string $workspaceId, string $intentId): array
    {
        $aggregate = $this->aggregates->forExecutionIntent($workspaceId, $intentId);
        $targets = array_map(
            static fn (PublicationTargetOutcome $target): array => [
                'target_id' => $target->targetId,
                'channel' => $target->channel,
                'state' => $target->state->value,
                'retry_eligible' => $target->retryEligible,
            ],
            $aggregate->targets,
        );

        return [
            'execution_intent_id' => $aggregate->executionIntentId,
            'state' => $aggregate->state->value,
            'retry_eligible_count' => count($aggregate->retryAttemptIds),
            'counts' => $this->targetCounts($targets),
            'targets' => $targets,
        ];
    }

    /** @return array<string, mixed> */
    private function notStartedPublication(CampaignSnapshot $snapshot): array
    {
        $targets = array_map(
            static fn ($target): array => [
                'target_id' => $target->id,
                'channel' => $target->channel,
                'state' => 'not_started',
                'retry_eligible' => false,
            ],
            $snapshot->targets,
        );

        return [
            'execution_intent_id' => null,
            'state' => 'not_started',
            'retry_eligible_count' => 0,
            'counts' => $this->targetCounts($targets),
            'targets' => $targets,
        ];
    }

    /**
     * @param  list<array{target_id: string, channel: string, state: string, retry_eligible: bool}>  $targets
     * @return array<string, int>
     */
    private function targetCounts(array $targets): array
    {
        $counts = [
            'total' => count($targets),
            'succeeded' => 0,
            'pending' => 0,
            'in_progress' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($targets as $target) {
            match ($target['state']) {
                'succeeded' => $counts['succeeded']++,
                'in_progress' => $counts['in_progress']++,
                'failed_retriable', 'failed_terminal', 'failed_unclassified' => $counts['failed']++,
                'cancelled' => $counts['cancelled']++,
                default => $counts['pending']++,
            };
        }

        return $counts;
    }
}
