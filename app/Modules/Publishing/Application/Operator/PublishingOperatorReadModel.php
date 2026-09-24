<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Publication\PublicationAggregateService;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcome;
use Illuminate\Database\DatabaseManager;
use JsonException;
use stdClass;

final readonly class PublishingOperatorReadModel
{
    public function __construct(
        private DatabaseManager $database,
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
        $connection = $this->database->connection();

        $snapshot = $connection->table('campaign_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();

        if (! $snapshot instanceof stdClass) {
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

        $targets = $connection->table('campaign_targets')
            ->select(['id', 'channel'])
            ->where('workspace_id', $workspaceId)
            ->where('snapshot_id', (string) $snapshot->id)
            ->orderBy('id')
            ->get()
            ->all();

        $approval = $connection->table('campaign_approval_decisions')
            ->select(['outcome', 'actor_role', 'occurred_at', 'expires_at'])
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', (string) $snapshot->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $schedule = $connection->table('campaign_schedules')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', (string) $snapshot->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $intentId = $connection->table('campaign_schedule_execution_intents')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', (string) $snapshot->id)
            ->orderByDesc('emitted_at')
            ->orderByDesc('id')
            ->value('id');

        return [
            'id' => $campaignId,
            'name' => (string) $campaign->name,
            'status' => (string) $campaign->status,
            'state_version' => (int) $campaign->state_version,
            'snapshot' => $this->snapshot($snapshot, $targets),
            'approval' => $approval instanceof stdClass ? [
                'outcome' => (string) $approval->outcome,
                'actor_role' => (string) $approval->actor_role,
                'occurred_at' => (string) $approval->occurred_at,
                'expires_at' => $approval->expires_at === null ? null : (string) $approval->expires_at,
                'revoked' => (string) $approval->outcome === 'revoked',
            ] : null,
            'schedule' => $schedule instanceof stdClass ? [
                'strategy' => (string) $schedule->strategy,
                'timezone_id' => (string) $schedule->timezone_id,
                'local_scheduled_at' => (string) $schedule->local_scheduled_at,
                'resolved_at_utc' => (string) $schedule->resolved_at_utc,
            ] : null,
            'publication' => is_string($intentId) && $intentId !== ''
                ? $this->publication($workspaceId, $intentId)
                : $this->notStartedPublication($targets),
        ];
    }

    /**
     * @param  list<stdClass>  $targets
     * @return array<string, mixed>
     */
    private function snapshot(stdClass $snapshot, array $targets): array
    {
        $channels = array_values(array_unique(array_map(
            static fn (stdClass $target): string => (string) $target->channel,
            $targets,
        )));
        sort($channels, SORT_STRING);

        $execution = [];
        foreach (['mode', 'timezone', 'at', 'channel'] as $key) {
            $value = $this->decodeJsonObject($snapshot->intended_execution)[$key] ?? null;
            if (is_string($value) || is_int($value) || is_bool($value)) {
                $execution[$key] = $value;
            }
        }

        return [
            'id' => (string) $snapshot->id,
            'version_number' => (int) $snapshot->version_number,
            'content_version_id' => (string) $snapshot->content_version_id,
            'snapshot_hash' => (string) $snapshot->snapshot_hash,
            'target_set_hash' => (string) $snapshot->target_set_hash,
            'target_count' => count($targets),
            'channels' => $channels,
            'intended_execution' => $execution,
            'created_at' => (string) $snapshot->created_at,
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

    /**
     * @param  list<stdClass>  $targets
     * @return array<string, mixed>
     */
    private function notStartedPublication(array $targets): array
    {
        $items = array_map(
            static fn (stdClass $target): array => [
                'target_id' => (string) $target->id,
                'channel' => (string) $target->channel,
                'state' => 'not_started',
                'retry_eligible' => false,
            ],
            $targets,
        );

        return [
            'execution_intent_id' => null,
            'state' => 'not_started',
            'retry_eligible_count' => 0,
            'counts' => $this->targetCounts($items),
            'targets' => $items,
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

    /** @return array<string, mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
