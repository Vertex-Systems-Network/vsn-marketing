<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Scheduling\CampaignSchedule;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleStrategy;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseCampaignScheduleRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function create(CampaignSchedule $schedule): CampaignSchedule
    {
        return $this->database->connection()->transaction(function () use ($schedule): CampaignSchedule {
            $campaign = $this->database->connection()->table('campaigns')
                ->where('workspace_id', $schedule->workspaceId)
                ->where('id', $schedule->campaignId)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof stdClass) {
                $this->denyIfForeignReferenceExists('campaigns', $schedule->workspaceId, $schedule->campaignId);
                throw new InvalidArgumentException('Campaign schedule campaign does not exist in this workspace.');
            }

            if (CampaignStatus::from((string) $campaign->status) !== CampaignStatus::ScheduledIntent) {
                throw new InvalidArgumentException('Campaign schedule requires scheduled_intent lifecycle state.');
            }

            $snapshot = $this->database->connection()->table('campaign_snapshots')
                ->where('workspace_id', $schedule->workspaceId)
                ->where('id', $schedule->snapshotId)
                ->first();

            if (! $snapshot instanceof stdClass) {
                $this->denyIfForeignReferenceExists('campaign_snapshots', $schedule->workspaceId, $schedule->snapshotId);
                throw new InvalidArgumentException('Campaign schedule snapshot does not exist in this workspace.');
            }

            if (
                (string) $snapshot->campaign_id !== $schedule->campaignId
                || ! hash_equals((string) $snapshot->target_set_hash, $schedule->targetSetHash)
            ) {
                throw new InvalidArgumentException('Campaign schedule snapshot binding does not match the canonical campaign target set.');
            }

            $latestSnapshotId = $this->database->connection()->table('campaign_snapshots')
                ->where('workspace_id', $schedule->workspaceId)
                ->where('campaign_id', $schedule->campaignId)
                ->orderByDesc('version_number')
                ->value('id');

            if ((string) $latestSnapshotId !== $schedule->snapshotId) {
                throw new InvalidArgumentException('Campaign schedule must bind the latest immutable campaign snapshot.');
            }

            try {
                $intendedExecution = json_decode(
                    (string) $snapshot->intended_execution,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'Campaign schedule snapshot intended execution is not valid JSON.',
                    previous: $exception,
                );
            }

            if (
                ! is_array($intendedExecution)
                || ($intendedExecution['mode'] ?? null) !== $schedule->strategy->value
            ) {
                throw new InvalidArgumentException(
                    'Campaign schedule strategy does not match the immutable snapshot intended execution.',
                );
            }

            if ($schedule->strategy === CampaignScheduleStrategy::QueueNextSlot) {
                $this->assertQueueBinding($schedule, $intendedExecution);
            }

            $approval = $this->database->connection()->table('campaign_approval_decisions')
                ->where('workspace_id', $schedule->workspaceId)
                ->where('id', $schedule->approvalId)
                ->first();

            if (! $approval instanceof stdClass) {
                $this->denyIfForeignReferenceExists(
                    'campaign_approval_decisions',
                    $schedule->workspaceId,
                    $schedule->approvalId,
                );
                throw new InvalidArgumentException('Campaign schedule approval does not exist in this workspace.');
            }

            $latestApproval = $this->database->connection()->table('campaign_approval_decisions')
                ->where('workspace_id', $schedule->workspaceId)
                ->where('campaign_id', $schedule->campaignId)
                ->where('snapshot_id', $schedule->snapshotId)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first();

            if (
                ! $latestApproval instanceof stdClass
                || (string) $latestApproval->id !== $schedule->approvalId
                || CampaignApprovalOutcome::from((string) $latestApproval->outcome) !== CampaignApprovalOutcome::Approved
                || (string) $approval->campaign_id !== $schedule->campaignId
                || (string) $approval->snapshot_id !== $schedule->snapshotId
                || ! hash_equals((string) $approval->target_set_hash, $schedule->targetSetHash)
            ) {
                throw new InvalidArgumentException('Campaign schedule requires the current approved decision for the exact snapshot and target set.');
            }

            if (
                $approval->expires_at !== null
                && new DateTimeImmutable((string) $approval->expires_at) <= $schedule->createdAt
            ) {
                throw new InvalidArgumentException('Campaign schedule approval expired before schedule creation.');
            }

            $existing = $this->database->connection()->table('campaign_schedules')
                ->where('workspace_id', $schedule->workspaceId)
                ->where('idempotency_key', $schedule->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrate($existing);
                $this->assertReplay($stored, $schedule);

                return $stored;
            }

            $this->denyIfForeignReferenceExists('campaign_schedules', $schedule->workspaceId, $schedule->id);

            $inserted = $this->database->connection()->table('campaign_schedules')->insertOrIgnore([
                'id' => $schedule->id,
                'workspace_id' => $schedule->workspaceId,
                'campaign_id' => $schedule->campaignId,
                'snapshot_id' => $schedule->snapshotId,
                'approval_id' => $schedule->approvalId,
                'target_set_hash' => $schedule->targetSetHash,
                'strategy' => $schedule->strategy->value,
                'timezone_id' => $schedule->timezoneId,
                'local_scheduled_at' => $schedule->localScheduledAt,
                'resolved_at_utc' => $schedule->resolvedAtUtc,
                'schedule_hash' => $schedule->scheduleHash,
                'idempotency_key' => $schedule->idempotencyKey,
                'created_by_actor_id' => $schedule->createdByActorId,
                'created_at' => $schedule->createdAt,
            ]);

            if ($inserted !== 1) {
                $winner = $this->database->connection()->table('campaign_schedules')
                    ->where('workspace_id', $schedule->workspaceId)
                    ->where(function ($query) use ($schedule): void {
                        $query->where('id', $schedule->id)
                            ->orWhere('idempotency_key', $schedule->idempotencyKey);
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $winner instanceof stdClass) {
                    throw new InvalidArgumentException('Campaign schedule identity or idempotency key conflicts with existing state.');
                }

                $stored = $this->hydrate($winner);
                $this->assertReplay($stored, $schedule);

                return $stored;
            }

            if ($schedule->strategy === CampaignScheduleStrategy::QueueNextSlot) {
                $bindingInserted = $this->database->connection()
                    ->table('campaign_queue_schedule_bindings')
                    ->insertOrIgnore([
                        'schedule_id' => $schedule->id,
                        'workspace_id' => $schedule->workspaceId,
                        'rule_set_id' => $schedule->ruleSetId,
                        'rule_version' => $schedule->ruleVersion,
                        'rule_hash' => $schedule->ruleHash,
                        'channel' => $schedule->channel,
                        'created_at' => $schedule->createdAt,
                    ]);

                if ($bindingInserted !== 1) {
                    throw new InvalidArgumentException(
                        'Campaign queue schedule binding conflicts with existing immutable state.',
                    );
                }
            }

            return $schedule;
        });
    }

    public function findByIdempotency(string $workspaceId, string $idempotencyKey): ?CampaignSchedule
    {
        $row = $this->database->connection()->table('campaign_schedules')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function find(string $workspaceId, string $scheduleId): ?CampaignSchedule
    {
        $row = $this->database->connection()->table('campaign_schedules')
            ->where('workspace_id', $workspaceId)
            ->where('id', $scheduleId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignReferenceExists('campaign_schedules', $workspaceId, $scheduleId);

        return null;
    }

    private function denyIfForeignReferenceExists(string $table, string $workspaceId, string $id): void
    {
        if ($this->database->connection()->table($table)
            ->where('id', $id)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign schedule reference access denied.');
        }
    }

    private function hydrate(stdClass $row): CampaignSchedule
    {
        $strategy = CampaignScheduleStrategy::from((string) $row->strategy);
        $binding = null;

        if ($strategy === CampaignScheduleStrategy::QueueNextSlot) {
            $binding = $this->database->connection()->table('campaign_queue_schedule_bindings')
                ->where('workspace_id', (string) $row->workspace_id)
                ->where('schedule_id', (string) $row->id)
                ->first();

            if (! $binding instanceof stdClass) {
                throw new InvalidArgumentException(
                    'Campaign queue schedule is missing its immutable rule binding.',
                );
            }
        }

        return new CampaignSchedule(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            approvalId: (string) $row->approval_id,
            targetSetHash: (string) $row->target_set_hash,
            strategy: $strategy,
            timezoneId: (string) $row->timezone_id,
            localScheduledAt: (string) $row->local_scheduled_at,
            resolvedAtUtc: (new DateTimeImmutable((string) $row->resolved_at_utc))->setTimezone(new DateTimeZone('UTC')),
            scheduleHash: (string) $row->schedule_hash,
            idempotencyKey: (string) $row->idempotency_key,
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            channel: $binding instanceof stdClass ? (string) $binding->channel : null,
            ruleSetId: $binding instanceof stdClass ? (string) $binding->rule_set_id : null,
            ruleVersion: $binding instanceof stdClass ? (int) $binding->rule_version : null,
            ruleHash: $binding instanceof stdClass ? (string) $binding->rule_hash : null,
        );
    }

    /** @param array<string, mixed> $intendedExecution */
    private function assertQueueBinding(CampaignSchedule $schedule, array $intendedExecution): void
    {
        if (
            ($intendedExecution['rule_set_id'] ?? null) !== $schedule->ruleSetId
            || ($intendedExecution['channel'] ?? null) !== $schedule->channel
        ) {
            throw new InvalidArgumentException(
                'Campaign queue schedule rule/channel does not match immutable snapshot intended execution.',
            );
        }

        $rule = $this->database->connection()->table('campaign_schedule_rule_sets')
            ->where('workspace_id', $schedule->workspaceId)
            ->where('id', $schedule->ruleSetId)
            ->first();

        if (! $rule instanceof stdClass) {
            $this->denyIfForeignReferenceExists(
                'campaign_schedule_rule_sets',
                $schedule->workspaceId,
                (string) $schedule->ruleSetId,
            );
            throw new InvalidArgumentException(
                'Campaign queue schedule rule set does not exist in this workspace.',
            );
        }

        if (
            (string) $rule->channel !== $schedule->channel
            || (int) $rule->version_number !== $schedule->ruleVersion
            || (string) $rule->timezone_id !== $schedule->timezoneId
            || ! hash_equals((string) $rule->rule_hash, (string) $schedule->ruleHash)
        ) {
            throw new InvalidArgumentException(
                'Campaign queue schedule rule binding does not match immutable rule-set evidence.',
            );
        }
    }

    private function assertReplay(CampaignSchedule $stored, CampaignSchedule $requested): void
    {
        if (
            $stored->id !== $requested->id
            || $stored->workspaceId !== $requested->workspaceId
            || $stored->campaignId !== $requested->campaignId
            || $stored->snapshotId !== $requested->snapshotId
            || $stored->approvalId !== $requested->approvalId
            || $stored->idempotencyKey !== $requested->idempotencyKey
            || $stored->createdByActorId !== $requested->createdByActorId
            || $stored->createdAt->format('U.u') !== $requested->createdAt->format('U.u')
            || ! hash_equals($stored->scheduleHash, $requested->scheduleHash)
        ) {
            throw new InvalidArgumentException('Campaign schedule replay conflicts with existing immutable schedule state.');
        }
    }
}
