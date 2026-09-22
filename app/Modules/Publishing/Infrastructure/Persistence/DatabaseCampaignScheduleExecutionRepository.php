<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleDueClaim;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleDueClaimState;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleExecutionIntent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseCampaignScheduleExecutionRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function findClaimBySchedule(
        string $workspaceId,
        string $scheduleId,
        bool $lock = false,
    ): ?CampaignScheduleDueClaim {
        $query = $this->database->connection()->table('campaign_schedule_due_claims')
            ->where('workspace_id', $workspaceId)
            ->where('schedule_id', $scheduleId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrateClaim($row) : null;
    }

    public function createClaim(CampaignScheduleDueClaim $claim): CampaignScheduleDueClaim
    {
        $schedule = $this->database->connection()->table('campaign_schedules')
            ->where('workspace_id', $claim->workspaceId)
            ->where('id', $claim->scheduleId)
            ->lockForUpdate()
            ->first();

        if (! $schedule instanceof stdClass) {
            if ($this->database->connection()->table('campaign_schedules')
                ->where('id', $claim->scheduleId)
                ->where('workspace_id', '<>', $claim->workspaceId)
                ->exists()) {
                throw new AuthorizationException('Campaign schedule due claim source access denied.');
            }

            throw new InvalidArgumentException('Campaign schedule due claim source does not exist in this workspace.');
        }

        if (
            (string) $schedule->campaign_id !== $claim->campaignId
            || (string) $schedule->snapshot_id !== $claim->snapshotId
            || (string) $schedule->approval_id !== $claim->scheduledApprovalId
            || ! hash_equals((string) $schedule->schedule_hash, $claim->scheduleHash)
            || $this->utc((string) $schedule->resolved_at_utc)->format('U.u')
                !== $claim->claimedAt->format('U.u')
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule due claim does not match the immutable exact-due schedule evidence.',
            );
        }

        $campaign = $this->database->connection()->table('campaigns')
            ->where('workspace_id', $claim->workspaceId)
            ->where('id', $claim->campaignId)
            ->lockForUpdate()
            ->first();

        if (! $campaign instanceof stdClass || (string) $campaign->status !== 'scheduled_intent') {
            throw new InvalidArgumentException(
                'Campaign schedule due claim requires scheduled_intent lifecycle state.',
            );
        }

        $approval = $this->database->connection()->table('campaign_approval_decisions')
            ->where('workspace_id', $claim->workspaceId)
            ->where('id', $claim->evaluatedApprovalId)
            ->first();

        if (
            ! $approval instanceof stdClass
            || (string) $approval->campaign_id !== $claim->campaignId
            || (string) $approval->snapshot_id !== $claim->snapshotId
            || (string) $approval->outcome !== 'approved'
            || ! hash_equals((string) $approval->target_set_hash, (string) $schedule->target_set_hash)
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule due claim evaluated approval does not match current approved schedule evidence.',
            );
        }

        if ($this->hasTerminalScheduleHistory($claim->workspaceId, $claim->scheduleId)) {
            throw new InvalidArgumentException(
                'Campaign schedule due claim cannot be created after terminal occurrence history.',
            );
        }

        $this->assertNoForeignClaim($claim->workspaceId, $claim->id);

        $inserted = $this->database->connection()->table('campaign_schedule_due_claims')->insertOrIgnore([
            'id' => $claim->id,
            'workspace_id' => $claim->workspaceId,
            'campaign_id' => $claim->campaignId,
            'snapshot_id' => $claim->snapshotId,
            'schedule_id' => $claim->scheduleId,
            'schedule_hash' => $claim->scheduleHash,
            'scheduled_approval_id' => $claim->scheduledApprovalId,
            'evaluated_approval_id' => $claim->evaluatedApprovalId,
            'state' => $claim->state->value,
            'lease_owner' => $claim->leaseOwner,
            'lease_token_hash' => $claim->leaseTokenHash,
            'lease_expires_at' => $claim->leaseExpiresAt,
            'claimed_at' => $claim->claimedAt,
            'attempt_number' => $claim->attemptNumber,
            'version' => $claim->version,
            'updated_at' => $claim->updatedAt,
        ]);

        if ($inserted === 1) {
            return $claim;
        }

        $winner = $this->database->connection()->table('campaign_schedule_due_claims')
            ->where('workspace_id', $claim->workspaceId)
            ->where(function ($query) use ($claim): void {
                $query->where('schedule_id', $claim->scheduleId)
                    ->orWhere('id', $claim->id)
                    ->orWhere('lease_token_hash', $claim->leaseTokenHash);
            })
            ->lockForUpdate()
            ->first();

        if (! $winner instanceof stdClass) {
            throw new InvalidArgumentException('Campaign schedule due claim conflicts with existing coordination state.');
        }

        $stored = $this->hydrateClaim($winner);
        if (
            $stored->id !== $claim->id
            || $stored->scheduleId !== $claim->scheduleId
            || $stored->leaseOwner !== $claim->leaseOwner
            || ! hash_equals($stored->leaseTokenHash, $claim->leaseTokenHash)
            || ! hash_equals($stored->scheduleHash, $claim->scheduleHash)
        ) {
            throw new InvalidArgumentException('Campaign schedule due claim identity conflicts with existing coordination state.');
        }

        return $stored;
    }

    public function replaceLease(
        CampaignScheduleDueClaim $current,
        CampaignScheduleDueClaim $replacement,
    ): CampaignScheduleDueClaim {
        if (
            $current->id !== $replacement->id
            || $current->workspaceId !== $replacement->workspaceId
            || $current->scheduleId !== $replacement->scheduleId
            || $replacement->version !== $current->version + 1
            || $replacement->attemptNumber !== $current->attemptNumber + 1
            || $current->leaseExpiresAt > $replacement->updatedAt
            || $replacement->leaseExpiresAt <= $replacement->updatedAt
        ) {
            throw new InvalidArgumentException('Campaign schedule lease replacement does not preserve claim identity/version lineage.');
        }

        $updated = $this->database->connection()->table('campaign_schedule_due_claims')
            ->where('id', $current->id)
            ->where('workspace_id', $current->workspaceId)
            ->where('schedule_id', $current->scheduleId)
            ->where('version', $current->version)
            ->where('state', CampaignScheduleDueClaimState::Leased->value)
            ->where('lease_token_hash', $current->leaseTokenHash)
            ->update([
                'lease_owner' => $replacement->leaseOwner,
                'lease_token_hash' => $replacement->leaseTokenHash,
                'lease_expires_at' => $replacement->leaseExpiresAt,
                'attempt_number' => $replacement->attemptNumber,
                'version' => $replacement->version,
                'updated_at' => $replacement->updatedAt,
            ]);

        if ($updated !== 1) {
            throw new InvalidArgumentException('Campaign schedule stale lease takeover raced with another worker.');
        }

        return $replacement;
    }

    public function markEmitted(
        CampaignScheduleDueClaim $current,
        DateTimeImmutable $at,
    ): CampaignScheduleDueClaim {
        if ($current->leaseExpiresAt <= $at) {
            throw new InvalidArgumentException('Campaign schedule expired lease cannot become emitted.');
        }

        $intentExists = $this->database->connection()->table('campaign_schedule_execution_intents')
            ->where('workspace_id', $current->workspaceId)
            ->where('schedule_id', $current->scheduleId)
            ->where('claim_id', $current->id)
            ->exists();

        if (! $intentExists) {
            throw new InvalidArgumentException(
                'Campaign schedule due claim cannot become emitted without immutable execution intent evidence.',
            );
        }

        $emitted = $current->emittedAt($at);
        $updated = $this->database->connection()->table('campaign_schedule_due_claims')
            ->where('id', $current->id)
            ->where('workspace_id', $current->workspaceId)
            ->where('schedule_id', $current->scheduleId)
            ->where('version', $current->version)
            ->where('state', CampaignScheduleDueClaimState::Leased->value)
            ->where('lease_token_hash', $current->leaseTokenHash)
            ->update([
                'state' => CampaignScheduleDueClaimState::Emitted->value,
                'version' => $emitted->version,
                'updated_at' => $emitted->updatedAt,
            ]);

        if ($updated !== 1) {
            throw new InvalidArgumentException('Campaign schedule execution intent emission raced with another worker.');
        }

        return $emitted;
    }

    public function findIntentBySchedule(
        string $workspaceId,
        string $scheduleId,
        bool $lock = false,
    ): ?CampaignScheduleExecutionIntent {
        $query = $this->database->connection()->table('campaign_schedule_execution_intents')
            ->where('workspace_id', $workspaceId)
            ->where('schedule_id', $scheduleId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrateIntent($row) : null;
    }

    public function createIntent(CampaignScheduleExecutionIntent $intent): CampaignScheduleExecutionIntent
    {
        $claim = $this->database->connection()->table('campaign_schedule_due_claims')
            ->where('id', $intent->claimId)
            ->where('workspace_id', $intent->workspaceId)
            ->where('schedule_id', $intent->scheduleId)
            ->lockForUpdate()
            ->first();

        if (! $claim instanceof stdClass) {
            $this->assertNoForeignClaim($intent->workspaceId, $intent->claimId);
            throw new InvalidArgumentException('Campaign schedule execution intent claim does not exist in this workspace.');
        }

        if (
            (string) $claim->campaign_id !== $intent->campaignId
            || (string) $claim->snapshot_id !== $intent->snapshotId
            || (string) $claim->scheduled_approval_id !== $intent->scheduledApprovalId
            || (string) $claim->evaluated_approval_id !== $intent->evaluatedApprovalId
            || (int) $claim->version !== $intent->claimVersion
            || (string) $claim->state !== CampaignScheduleDueClaimState::Leased->value
            || ! hash_equals((string) $claim->schedule_hash, $intent->scheduleHash)
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule execution intent does not match current lease coordination evidence.',
            );
        }

        $outbox = $this->database->connection()->table('outbox_messages')
            ->where('id', $intent->outboxId)
            ->first();

        if (
            ! $outbox instanceof stdClass
            || (string) $outbox->topic !== 'publishing.campaign_schedule.execution_intent.ready'
            || (string) $outbox->aggregate_type !== 'campaign_schedule_execution_intent'
            || (string) $outbox->aggregate_id !== $intent->id
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule execution intent requires its exact durable outbox handoff.',
            );
        }

        try {
            $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
            $headers = json_decode((string) $outbox->headers, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Campaign schedule execution intent outbox evidence is not valid JSON.',
                previous: $exception,
            );
        }

        if (
            ! is_array($payload)
            || ! is_array($headers)
            || ($payload['workspace_id'] ?? null) !== $intent->workspaceId
            || ($payload['campaign_id'] ?? null) !== $intent->campaignId
            || ($payload['snapshot_id'] ?? null) !== $intent->snapshotId
            || ($payload['schedule_id'] ?? null) !== $intent->scheduleId
            || ($payload['execution_intent_id'] ?? null) !== $intent->id
            || ($payload['intent_hash'] ?? null) !== $intent->intentHash
            || ($payload['resolved_at_utc'] ?? null)
                !== $intent->resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z')
            || ($headers['schema_version'] ?? null) !== 1
            || ($headers['source'] ?? null) !== 'task0039.scheduler'
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule execution intent outbox payload does not match immutable intent evidence.',
            );
        }

        $inserted = $this->database->connection()->table('campaign_schedule_execution_intents')->insertOrIgnore([
            'id' => $intent->id,
            'workspace_id' => $intent->workspaceId,
            'campaign_id' => $intent->campaignId,
            'snapshot_id' => $intent->snapshotId,
            'schedule_id' => $intent->scheduleId,
            'schedule_hash' => $intent->scheduleHash,
            'scheduled_approval_id' => $intent->scheduledApprovalId,
            'evaluated_approval_id' => $intent->evaluatedApprovalId,
            'claim_id' => $intent->claimId,
            'claim_version' => $intent->claimVersion,
            'resolved_at_utc' => $intent->resolvedAtUtc,
            'claimed_at' => $intent->claimedAt,
            'emitted_at' => $intent->emittedAt,
            'outbox_id' => $intent->outboxId,
            'intent_hash' => $intent->intentHash,
        ]);

        if ($inserted === 1) {
            return $intent;
        }

        $winner = $this->database->connection()->table('campaign_schedule_execution_intents')
            ->where('workspace_id', $intent->workspaceId)
            ->where(function ($query) use ($intent): void {
                $query->where('schedule_id', $intent->scheduleId)
                    ->orWhere('id', $intent->id)
                    ->orWhere('outbox_id', $intent->outboxId);
            })
            ->lockForUpdate()
            ->first();

        if (! $winner instanceof stdClass) {
            throw new InvalidArgumentException('Campaign schedule execution intent conflicts with existing immutable state.');
        }

        $stored = $this->hydrateIntent($winner);
        if (
            $stored->workspaceId !== $intent->workspaceId
            || $stored->campaignId !== $intent->campaignId
            || $stored->snapshotId !== $intent->snapshotId
            || $stored->scheduleId !== $intent->scheduleId
            || $stored->scheduledApprovalId !== $intent->scheduledApprovalId
            || $stored->evaluatedApprovalId !== $intent->evaluatedApprovalId
            || $stored->claimId !== $intent->claimId
            || ! hash_equals($stored->scheduleHash, $intent->scheduleHash)
        ) {
            throw new InvalidArgumentException('Campaign schedule execution intent replay conflicts with canonical occurrence evidence.');
        }

        return $stored;
    }

    public function hasTerminalScheduleHistory(string $workspaceId, string $scheduleId): bool
    {
        return $this->database->connection()->table('campaign_schedule_mutations')
            ->where('workspace_id', $workspaceId)
            ->where('previous_schedule_id', $scheduleId)
            ->exists()
            || $this->database->connection()->table('campaign_schedule_occurrence_outcomes')
                ->where('workspace_id', $workspaceId)
                ->where('schedule_id', $scheduleId)
                ->exists();
    }

    public function assertNoForeignClaim(string $workspaceId, string $claimId): void
    {
        if ($this->database->connection()->table('campaign_schedule_due_claims')
            ->where('id', $claimId)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign schedule due claim access denied.');
        }
    }

    private function hydrateClaim(stdClass $row): CampaignScheduleDueClaim
    {
        return new CampaignScheduleDueClaim(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            scheduleId: (string) $row->schedule_id,
            scheduleHash: (string) $row->schedule_hash,
            scheduledApprovalId: (string) $row->scheduled_approval_id,
            evaluatedApprovalId: (string) $row->evaluated_approval_id,
            state: CampaignScheduleDueClaimState::from((string) $row->state),
            leaseOwner: (string) $row->lease_owner,
            leaseTokenHash: (string) $row->lease_token_hash,
            leaseExpiresAt: $this->utc((string) $row->lease_expires_at),
            claimedAt: $this->utc((string) $row->claimed_at),
            attemptNumber: (int) $row->attempt_number,
            version: (int) $row->version,
            updatedAt: $this->utc((string) $row->updated_at),
        );
    }

    private function hydrateIntent(stdClass $row): CampaignScheduleExecutionIntent
    {
        return new CampaignScheduleExecutionIntent(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            scheduleId: (string) $row->schedule_id,
            scheduleHash: (string) $row->schedule_hash,
            scheduledApprovalId: (string) $row->scheduled_approval_id,
            evaluatedApprovalId: (string) $row->evaluated_approval_id,
            claimId: (string) $row->claim_id,
            claimVersion: (int) $row->claim_version,
            resolvedAtUtc: $this->utc((string) $row->resolved_at_utc),
            claimedAt: $this->utc((string) $row->claimed_at),
            emittedAt: $this->utc((string) $row->emitted_at),
            outboxId: (string) $row->outbox_id,
            intentHash: (string) $row->intent_hash,
        );
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
