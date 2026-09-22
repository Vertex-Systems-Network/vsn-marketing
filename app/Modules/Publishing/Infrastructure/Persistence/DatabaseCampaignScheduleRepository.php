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

            return $schedule;
        });
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
        return new CampaignSchedule(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            approvalId: (string) $row->approval_id,
            targetSetHash: (string) $row->target_set_hash,
            strategy: CampaignScheduleStrategy::from((string) $row->strategy),
            timezoneId: (string) $row->timezone_id,
            localScheduledAt: (string) $row->local_scheduled_at,
            resolvedAtUtc: (new DateTimeImmutable((string) $row->resolved_at_utc))->setTimezone(new DateTimeZone('UTC')),
            scheduleHash: (string) $row->schedule_hash,
            idempotencyKey: (string) $row->idempotency_key,
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
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
