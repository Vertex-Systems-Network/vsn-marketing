<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleMutation;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleMutationType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use stdClass;

final readonly class DatabaseCampaignScheduleMutationRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function create(CampaignScheduleMutation $mutation): CampaignScheduleMutation
    {
        return $this->database->connection()->transaction(function () use ($mutation): CampaignScheduleMutation {
            $existing = $this->mutationByIdempotency(
                $mutation->workspaceId,
                $mutation->idempotencyKey,
                true,
            );

            if ($existing instanceof stdClass) {
                $stored = $this->hydrate($existing);
                $this->assertReplay($stored, $mutation);

                return $stored;
            }

            $previous = $this->scheduleRow(
                $mutation->workspaceId,
                $mutation->previousScheduleId,
                true,
            );

            if (! $previous instanceof stdClass) {
                $this->denyIfForeignReferenceExists(
                    'campaign_schedules',
                    $mutation->workspaceId,
                    $mutation->previousScheduleId,
                );
                throw new InvalidArgumentException('Campaign schedule mutation source does not exist in this workspace.');
            }

            if (
                (string) $previous->campaign_id !== $mutation->campaignId
                || ! hash_equals((string) $previous->schedule_hash, $mutation->previousScheduleHash)
                || $this->utc((string) $previous->resolved_at_utc)->format('U.u')
                    !== $mutation->previousResolvedAtUtc->format('U.u')
            ) {
                throw new InvalidArgumentException('Campaign schedule mutation source evidence does not match immutable schedule state.');
            }

            $terminal = $this->database->connection()->table('campaign_schedule_mutations')
                ->where('workspace_id', $mutation->workspaceId)
                ->where('previous_schedule_id', $mutation->previousScheduleId)
                ->lockForUpdate()
                ->first();

            if ($terminal instanceof stdClass) {
                throw new InvalidArgumentException('Campaign schedule already has terminal reschedule/cancellation history.');
            }

            if ($mutation->type === CampaignScheduleMutationType::Rescheduled) {
                $replacement = $this->scheduleRow(
                    $mutation->workspaceId,
                    (string) $mutation->replacementScheduleId,
                    true,
                );

                if (! $replacement instanceof stdClass) {
                    $this->denyIfForeignReferenceExists(
                        'campaign_schedules',
                        $mutation->workspaceId,
                        (string) $mutation->replacementScheduleId,
                    );
                    throw new InvalidArgumentException('Campaign replacement schedule does not exist in this workspace.');
                }

                if (
                    (string) $replacement->campaign_id !== $mutation->campaignId
                    || ! hash_equals((string) $replacement->schedule_hash, (string) $mutation->replacementScheduleHash)
                    || $this->utc((string) $replacement->resolved_at_utc)->format('U.u')
                        !== $mutation->replacementResolvedAtUtc?->format('U.u')
                    || (new DateTimeImmutable((string) $replacement->created_at))->format('U.u')
                        !== $mutation->occurredAt->format('U.u')
                ) {
                    throw new InvalidArgumentException('Campaign replacement schedule evidence does not match immutable schedule state.');
                }
            }

            $this->denyIfForeignReferenceExists(
                'campaign_schedule_mutations',
                $mutation->workspaceId,
                $mutation->id,
            );

            $inserted = $this->database->connection()->table('campaign_schedule_mutations')->insertOrIgnore([
                'id' => $mutation->id,
                'workspace_id' => $mutation->workspaceId,
                'campaign_id' => $mutation->campaignId,
                'mutation_type' => $mutation->type->value,
                'previous_schedule_id' => $mutation->previousScheduleId,
                'previous_schedule_hash' => $mutation->previousScheduleHash,
                'previous_resolved_at_utc' => $mutation->previousResolvedAtUtc,
                'replacement_schedule_id' => $mutation->replacementScheduleId,
                'replacement_schedule_hash' => $mutation->replacementScheduleHash,
                'replacement_resolved_at_utc' => $mutation->replacementResolvedAtUtc,
                'actor_id' => $mutation->actorId,
                'reason' => $mutation->reason,
                'idempotency_key' => $mutation->idempotencyKey,
                'occurred_at' => $mutation->occurredAt,
                'mutation_hash' => $mutation->mutationHash,
            ]);

            if ($inserted !== 1) {
                $winner = $this->mutationByIdempotency(
                    $mutation->workspaceId,
                    $mutation->idempotencyKey,
                    true,
                ) ?? $this->database->connection()->table('campaign_schedule_mutations')
                    ->where('workspace_id', $mutation->workspaceId)
                    ->where('id', $mutation->id)
                    ->lockForUpdate()
                    ->first();

                if (! $winner instanceof stdClass) {
                    throw new InvalidArgumentException('Campaign schedule mutation conflicts with existing terminal history.');
                }

                $stored = $this->hydrate($winner);
                $this->assertReplay($stored, $mutation);

                return $stored;
            }

            return $mutation;
        });
    }

    public function findByIdempotency(string $workspaceId, string $idempotencyKey): ?CampaignScheduleMutation
    {
        $row = $this->mutationByIdempotency($workspaceId, $idempotencyKey, false);

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function find(string $workspaceId, string $mutationId): ?CampaignScheduleMutation
    {
        $row = $this->database->connection()->table('campaign_schedule_mutations')
            ->where('workspace_id', $workspaceId)
            ->where('id', $mutationId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignReferenceExists('campaign_schedule_mutations', $workspaceId, $mutationId);

        return null;
    }

    /** @return list<CampaignScheduleMutation> */
    public function history(string $workspaceId, string $campaignId): array
    {
        return $this->database->connection()->table('campaign_schedule_mutations')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): CampaignScheduleMutation => $this->hydrate($row))
            ->values()
            ->all();
    }

    private function mutationByIdempotency(
        string $workspaceId,
        string $idempotencyKey,
        bool $lock,
    ): ?stdClass {
        $query = $this->database->connection()->table('campaign_schedule_mutations')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function scheduleRow(string $workspaceId, string $scheduleId, bool $lock): ?stdClass
    {
        $query = $this->database->connection()->table('campaign_schedules')
            ->where('workspace_id', $workspaceId)
            ->where('id', $scheduleId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function hydrate(stdClass $row): CampaignScheduleMutation
    {
        return new CampaignScheduleMutation(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            type: CampaignScheduleMutationType::from((string) $row->mutation_type),
            previousScheduleId: (string) $row->previous_schedule_id,
            previousScheduleHash: (string) $row->previous_schedule_hash,
            previousResolvedAtUtc: $this->utc((string) $row->previous_resolved_at_utc),
            replacementScheduleId: $row->replacement_schedule_id === null
                ? null
                : (string) $row->replacement_schedule_id,
            replacementScheduleHash: $row->replacement_schedule_hash === null
                ? null
                : (string) $row->replacement_schedule_hash,
            replacementResolvedAtUtc: $row->replacement_resolved_at_utc === null
                ? null
                : $this->utc((string) $row->replacement_resolved_at_utc),
            actorId: (string) $row->actor_id,
            reason: (string) $row->reason,
            idempotencyKey: (string) $row->idempotency_key,
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            mutationHash: (string) $row->mutation_hash,
        );
    }

    private function assertReplay(
        CampaignScheduleMutation $stored,
        CampaignScheduleMutation $requested,
    ): void {
        if (
            $stored->id !== $requested->id
            || $stored->workspaceId !== $requested->workspaceId
            || $stored->campaignId !== $requested->campaignId
            || $stored->type !== $requested->type
            || $stored->previousScheduleId !== $requested->previousScheduleId
            || $stored->replacementScheduleId !== $requested->replacementScheduleId
            || $stored->actorId !== $requested->actorId
            || $stored->reason !== $requested->reason
            || $stored->idempotencyKey !== $requested->idempotencyKey
            || ! hash_equals($stored->mutationHash, $requested->mutationHash)
        ) {
            throw new InvalidArgumentException('Campaign schedule mutation replay conflicts with immutable history.');
        }
    }

    private function denyIfForeignReferenceExists(string $table, string $workspaceId, string $id): void
    {
        if ($this->database->connection()->table($table)
            ->where('id', $id)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign schedule mutation reference access denied.');
        }
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
