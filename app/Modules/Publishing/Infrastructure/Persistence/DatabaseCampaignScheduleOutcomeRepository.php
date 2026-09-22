<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Campaign\CampaignApprovalInvalidReason;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleMissedReason;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleOccurrenceOutcome;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use stdClass;

final readonly class DatabaseCampaignScheduleOutcomeRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function create(CampaignScheduleOccurrenceOutcome $outcome): CampaignScheduleOccurrenceOutcome
    {
        return $this->database->connection()->transaction(function () use ($outcome): CampaignScheduleOccurrenceOutcome {
            $existing = $this->outcomeByIdempotency(
                $outcome->workspaceId,
                $outcome->idempotencyKey,
                true,
            );

            if ($existing instanceof stdClass) {
                $stored = $this->hydrate($existing);
                $this->assertReplay($stored, $outcome);

                return $stored;
            }

            $schedule = $this->database->connection()->table('campaign_schedules')
                ->where('workspace_id', $outcome->workspaceId)
                ->where('id', $outcome->scheduleId)
                ->lockForUpdate()
                ->first();

            if (! $schedule instanceof stdClass) {
                $this->denyIfForeignReferenceExists(
                    'campaign_schedules',
                    $outcome->workspaceId,
                    $outcome->scheduleId,
                );
                throw new InvalidArgumentException('Campaign schedule outcome source does not exist in this workspace.');
            }

            if (
                (string) $schedule->campaign_id !== $outcome->campaignId
                || (string) $schedule->snapshot_id !== $outcome->snapshotId
                || (string) $schedule->approval_id !== $outcome->scheduledApprovalId
                || ! hash_equals((string) $schedule->schedule_hash, $outcome->scheduleHash)
                || $this->utc((string) $schedule->resolved_at_utc)->format('U.u')
                    !== $outcome->resolvedAtUtc->format('U.u')
            ) {
                throw new InvalidArgumentException('Campaign schedule outcome evidence does not match immutable schedule state.');
            }

            $terminalMutation = $this->database->connection()->table('campaign_schedule_mutations')
                ->where('workspace_id', $outcome->workspaceId)
                ->where('previous_schedule_id', $outcome->scheduleId)
                ->lockForUpdate()
                ->first();

            if ($terminalMutation instanceof stdClass) {
                throw new InvalidArgumentException(
                    'Campaign schedule outcome cannot be recorded after terminal reschedule/cancellation history.',
                );
            }

            if ($outcome->evaluatedDecisionId !== null) {
                $decision = $this->database->connection()->table('campaign_approval_decisions')
                    ->where('workspace_id', $outcome->workspaceId)
                    ->where('id', $outcome->evaluatedDecisionId)
                    ->first();

                if (! $decision instanceof stdClass) {
                    $this->denyIfForeignReferenceExists(
                        'campaign_approval_decisions',
                        $outcome->workspaceId,
                        $outcome->evaluatedDecisionId,
                    );
                    throw new InvalidArgumentException('Evaluated campaign approval decision does not exist in this workspace.');
                }

                if (
                    (string) $decision->campaign_id !== $outcome->campaignId
                    || (string) $decision->snapshot_id !== $outcome->snapshotId
                ) {
                    throw new InvalidArgumentException(
                        'Evaluated campaign approval decision does not match the immutable schedule snapshot.',
                    );
                }
            }

            $terminalOutcome = $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
                ->where('workspace_id', $outcome->workspaceId)
                ->where('schedule_id', $outcome->scheduleId)
                ->lockForUpdate()
                ->first();

            if ($terminalOutcome instanceof stdClass) {
                throw new InvalidArgumentException('Campaign schedule already has a terminal occurrence outcome.');
            }

            $this->denyIfForeignReferenceExists(
                'campaign_schedule_occurrence_outcomes',
                $outcome->workspaceId,
                $outcome->id,
            );

            $inserted = $this->database->connection()->table('campaign_schedule_occurrence_outcomes')->insertOrIgnore([
                'id' => $outcome->id,
                'workspace_id' => $outcome->workspaceId,
                'campaign_id' => $outcome->campaignId,
                'snapshot_id' => $outcome->snapshotId,
                'schedule_id' => $outcome->scheduleId,
                'schedule_hash' => $outcome->scheduleHash,
                'scheduled_approval_id' => $outcome->scheduledApprovalId,
                'evaluated_decision_id' => $outcome->evaluatedDecisionId,
                'missed_reason' => $outcome->missedReason->value,
                'approval_invalid_reason' => $outcome->approvalInvalidReason?->value,
                'approval_detail' => $outcome->approvalDetail,
                'resolved_at_utc' => $outcome->resolvedAtUtc,
                'observed_at' => $outcome->observedAt,
                'recorded_by_actor_id' => $outcome->recordedByActorId,
                'idempotency_key' => $outcome->idempotencyKey,
                'outcome_hash' => $outcome->outcomeHash,
            ]);

            if ($inserted !== 1) {
                $winner = $this->outcomeByIdempotency(
                    $outcome->workspaceId,
                    $outcome->idempotencyKey,
                    true,
                ) ?? $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
                    ->where('workspace_id', $outcome->workspaceId)
                    ->where('id', $outcome->id)
                    ->lockForUpdate()
                    ->first();

                if (! $winner instanceof stdClass) {
                    throw new InvalidArgumentException('Campaign schedule outcome conflicts with existing terminal history.');
                }

                $stored = $this->hydrate($winner);
                $this->assertReplay($stored, $outcome);

                return $stored;
            }

            return $outcome;
        });
    }

    public function findByIdempotency(
        string $workspaceId,
        string $idempotencyKey,
    ): ?CampaignScheduleOccurrenceOutcome {
        $row = $this->outcomeByIdempotency($workspaceId, $idempotencyKey, false);

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function find(string $workspaceId, string $outcomeId): ?CampaignScheduleOccurrenceOutcome
    {
        $row = $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
            ->where('workspace_id', $workspaceId)
            ->where('id', $outcomeId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignReferenceExists(
            'campaign_schedule_occurrence_outcomes',
            $workspaceId,
            $outcomeId,
        );

        return null;
    }

    public function findBySchedule(
        string $workspaceId,
        string $scheduleId,
    ): ?CampaignScheduleOccurrenceOutcome {
        $row = $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
            ->where('workspace_id', $workspaceId)
            ->where('schedule_id', $scheduleId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignReferenceExists('campaign_schedules', $workspaceId, $scheduleId);

        return null;
    }

    /** @return list<CampaignScheduleOccurrenceOutcome> */
    public function history(string $workspaceId, string $campaignId): array
    {
        $campaignExists = $this->database->connection()->table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('id', $campaignId)
            ->exists();

        if (! $campaignExists) {
            $this->denyIfForeignReferenceExists('campaigns', $workspaceId, $campaignId);
            throw new InvalidArgumentException('Campaign schedule outcome campaign does not exist in this workspace.');
        }

        return $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): CampaignScheduleOccurrenceOutcome => $this->hydrate($row))
            ->values()
            ->all();
    }

    private function outcomeByIdempotency(
        string $workspaceId,
        string $idempotencyKey,
        bool $lock,
    ): ?stdClass {
        $query = $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function hydrate(stdClass $row): CampaignScheduleOccurrenceOutcome
    {
        return new CampaignScheduleOccurrenceOutcome(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            scheduleId: (string) $row->schedule_id,
            scheduleHash: (string) $row->schedule_hash,
            scheduledApprovalId: (string) $row->scheduled_approval_id,
            evaluatedDecisionId: $row->evaluated_decision_id === null
                ? null
                : (string) $row->evaluated_decision_id,
            missedReason: CampaignScheduleMissedReason::from((string) $row->missed_reason),
            approvalInvalidReason: $row->approval_invalid_reason === null
                ? null
                : CampaignApprovalInvalidReason::from((string) $row->approval_invalid_reason),
            approvalDetail: $row->approval_detail === null ? null : (string) $row->approval_detail,
            resolvedAtUtc: $this->utc((string) $row->resolved_at_utc),
            observedAt: $this->utc((string) $row->observed_at),
            recordedByActorId: (string) $row->recorded_by_actor_id,
            idempotencyKey: (string) $row->idempotency_key,
            outcomeHash: (string) $row->outcome_hash,
        );
    }

    private function assertReplay(
        CampaignScheduleOccurrenceOutcome $stored,
        CampaignScheduleOccurrenceOutcome $requested,
    ): void {
        if (
            $stored->id !== $requested->id
            || $stored->workspaceId !== $requested->workspaceId
            || $stored->campaignId !== $requested->campaignId
            || $stored->snapshotId !== $requested->snapshotId
            || $stored->scheduleId !== $requested->scheduleId
            || $stored->scheduledApprovalId !== $requested->scheduledApprovalId
            || $stored->evaluatedDecisionId !== $requested->evaluatedDecisionId
            || $stored->missedReason !== $requested->missedReason
            || $stored->approvalInvalidReason !== $requested->approvalInvalidReason
            || $stored->recordedByActorId !== $requested->recordedByActorId
            || $stored->idempotencyKey !== $requested->idempotencyKey
            || ! hash_equals($stored->outcomeHash, $requested->outcomeHash)
        ) {
            throw new InvalidArgumentException('Campaign schedule outcome replay conflicts with immutable history.');
        }
    }

    private function denyIfForeignReferenceExists(string $table, string $workspaceId, string $id): void
    {
        if ($this->database->connection()->table($table)
            ->where('id', $id)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign schedule outcome reference access denied.');
        }
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
