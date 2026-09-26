<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Publication\PublicationAggregateService;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use JsonException;
use stdClass;

final readonly class PublishingOperatorReadModel
{
    private const PROVIDER_OPERATION = 'publication.create';

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
                'provider_attention' => array_sum(array_map(
                    fn (array $campaign): int => $this->providerAttentionCount($campaign),
                    $campaigns,
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
            ->select([
                'id',
                'kind',
                'channel',
                'provider_connection_id',
                'capability_evidence_id',
            ])
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
                ? $this->publication($workspaceId, $intentId, $targets)
                : $this->notStartedPublication($workspaceId, $targets),
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

    /**
     * @param  list<stdClass>  $canonicalTargets
     * @return array<string, mixed>
     */
    private function publication(string $workspaceId, string $intentId, array $canonicalTargets): array
    {
        $aggregate = $this->aggregates->forExecutionIntent($workspaceId, $intentId);
        $byId = [];
        foreach ($canonicalTargets as $target) {
            $byId[(string) $target->id] = $target;
        }

        $targets = array_map(
            function (PublicationTargetOutcome $target) use ($workspaceId, $byId): array {
                $canonical = $byId[$target->targetId] ?? null;

                return [
                    'target_id' => $target->targetId,
                    'channel' => $target->channel,
                    'state' => $target->state->value,
                    'retry_eligible' => $target->retryEligible,
                    'provider' => $canonical instanceof stdClass
                        ? $this->providerOutcome($workspaceId, $canonical)
                        : null,
                ];
            },
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
    private function notStartedPublication(string $workspaceId, array $targets): array
    {
        $items = array_map(
            fn (stdClass $target): array => [
                'target_id' => (string) $target->id,
                'channel' => (string) $target->channel,
                'state' => 'not_started',
                'retry_eligible' => false,
                'provider' => $this->providerOutcome($workspaceId, $target),
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

    /** @return array<string, mixed>|null */
    private function providerOutcome(string $workspaceId, stdClass $target): ?array
    {
        if (
            (string) ($target->kind ?? '') !== 'provider_connection'
            || ! is_string($target->provider_connection_id ?? null)
            || $target->provider_connection_id === ''
            || ! is_string($target->capability_evidence_id ?? null)
            || $target->capability_evidence_id === ''
        ) {
            return null;
        }

        $connection = $this->database->connection();
        $now = CarbonImmutable::now('UTC');
        $providerConnection = $connection->table('provider_connections')
            ->where('workspace_id', $workspaceId)
            ->where('id', (string) $target->provider_connection_id)
            ->first();

        if (! $providerConnection instanceof stdClass) {
            return $this->providerStatus('provider_disconnected', 'reconnect_provider');
        }

        $readiness = (string) $providerConnection->readiness_status;
        if ($readiness !== 'ready') {
            return match ($readiness) {
                'auth_required' => $this->providerStatus('credential_invalid', 'reauthenticate_provider'),
                'scope_required' => $this->providerStatus('permission_lost', 'reauthorize_permissions'),
                'provider_review_required' => $this->providerStatus('app_review_restricted', 'complete_provider_review'),
                default => $this->providerStatus('provider_disconnected', 'reconnect_provider'),
            };
        }

        if (
            $providerConnection->token_expires_at !== null
            && $this->time((string) $providerConnection->token_expires_at) <= $now
        ) {
            return $this->providerStatus('credential_invalid', 'reauthenticate_provider');
        }

        if (
            $providerConnection->provider_review_status !== null
            && mb_strtolower(trim((string) $providerConnection->provider_review_status)) !== 'approved'
        ) {
            return $this->providerStatus('app_review_restricted', 'complete_provider_review');
        }

        $capability = $connection->table('provider_capabilities')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', (string) $providerConnection->provider_id)
            ->where('connection_id', (string) $providerConnection->id)
            ->where('operation', self::PROVIDER_OPERATION)
            ->orderByDesc('observed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $capability instanceof stdClass) {
            return $this->providerStatus('capability_drift', 'refresh_provider_capability');
        }

        if (
            trim((string) ($providerConnection->source_version ?? '')) === ''
            || trim((string) ($capability->source_version ?? '')) === ''
            || $this->time((string) $providerConnection->observed_at) > $now
            || $this->time((string) $capability->observed_at) > $now
            || ($providerConnection->fresh_until !== null
                && $this->time((string) $providerConnection->fresh_until) <= $now)
            || ($capability->fresh_until !== null
                && $this->time((string) $capability->fresh_until) <= $now)
        ) {
            return $this->providerStatus('provider_authority_stale', 'refresh_provider_authority');
        }

        if (
            (string) $capability->support_status !== 'supported'
            || (string) $capability->id !== (string) $target->capability_evidence_id
        ) {
            return $this->providerStatus('capability_drift', 'refresh_provider_capability');
        }

        $requiredScopes = $this->decodeStringList($capability->required_scopes);
        $requiredRoles = $this->decodeStringList($capability->required_roles);
        $grantedScopes = $this->decodeStringList($providerConnection->granted_scopes);
        $roles = $this->decodeStringList($providerConnection->roles);

        if (
            $requiredScopes === null
            || $requiredRoles === null
            || $grantedScopes === null
            || $roles === null
            || array_diff($requiredScopes, $grantedScopes) !== []
            || array_diff($requiredRoles, $roles) !== []
        ) {
            return $this->providerStatus('permission_lost', 'reauthorize_permissions');
        }

        $breaker = $connection->table('delivery_circuit_breakers')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', (string) $providerConnection->provider_id)
            ->where('provider_connection_id', (string) $providerConnection->id)
            ->where('operation_class', self::PROVIDER_OPERATION)
            ->orderByDesc('updated_at')
            ->first();

        if ($breaker instanceof stdClass && in_array((string) $breaker->state, ['open', 'half_open'], true)) {
            return $this->providerStatus(
                (string) $breaker->state === 'open' ? 'circuit_open' : 'circuit_half_open',
                'wait_for_provider_probe',
                nextProbeAt: $breaker->next_probe_at === null ? null : (string) $breaker->next_probe_at,
            );
        }

        $quota = $connection->table('provider_quotas')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', (string) $providerConnection->provider_id)
            ->where('connection_id', (string) $providerConnection->id)
            ->where('operation', self::PROVIDER_OPERATION)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if (
            $quota instanceof stdClass
            && $quota->remaining_value !== null
            && (float) $quota->remaining_value <= 0.0
            && $quota->resets_at !== null
            && $this->time((string) $quota->resets_at) > $now
            && $this->time((string) $quota->observed_at) <= $now
            && ($quota->fresh_until === null || $this->time((string) $quota->fresh_until) > $now)
        ) {
            $retryAfter = max(0, (int) $now->diffInSeconds(
                $this->time((string) $quota->resets_at),
                absolute: false,
            ));

            return $this->providerStatus(
                'rate_limited',
                'wait_for_rate_reset',
                retryAfterSeconds: $retryAfter,
            );
        }

        return $this->providerStatus('ready', null, retryBlocked: false);
    }

    /**
     * @return array{status: string, action: string|null, retry_blocked: bool, retry_after_seconds: int|null, next_probe_at: string|null, evidence: string}
     */
    private function providerStatus(
        string $status,
        ?string $action,
        bool $retryBlocked = true,
        ?int $retryAfterSeconds = null,
        ?string $nextProbeAt = null,
    ): array {
        return [
            'status' => $status,
            'action' => $action,
            'retry_blocked' => $retryBlocked,
            'retry_after_seconds' => $retryAfterSeconds,
            'next_probe_at' => $nextProbeAt,
            'evidence' => 'canonical_provider_evidence',
        ];
    }

    /** @param array<string, mixed> $campaign */
    private function providerAttentionCount(array $campaign): int
    {
        $targets = $campaign['publication']['targets'] ?? [];
        if (! is_array($targets)) {
            return 0;
        }

        return count(array_filter(
            $targets,
            static fn (mixed $target): bool => is_array($target)
                && is_array($target['provider'] ?? null)
                && ($target['provider']['status'] ?? 'ready') !== 'ready',
        ));
    }

    /**
     * @param  list<array{target_id: string, channel: string, state: string, retry_eligible: bool, provider?: array<string, mixed>|null}>  $targets
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

    /** @return list<string>|null */
    private function decodeStringList(mixed $value): ?array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && $value !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        } else {
            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $item) {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }
            $result[] = $item;
        }

        return $result;
    }

    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
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
