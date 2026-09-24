<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabasePublicationAttemptRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function create(PublicationAttempt $attempt): PublicationAttempt
    {
        $this->assertAuthority($attempt);

        $existing = $this->database->connection()->table('publication_attempts')
            ->where('workspace_id', $attempt->workspaceId)
            ->where('execution_intent_id', $attempt->executionIntentId)
            ->where('target_id', $attempt->targetId)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof stdClass) {
            $stored = $this->hydrate($existing);
            $this->assertReplay($stored, $attempt);

            return $stored;
        }

        $this->denyIfForeignAttemptIdExists($attempt->workspaceId, $attempt->id);

        $inserted = $this->database->connection()->table('publication_attempts')->insertOrIgnore([
            'id' => $attempt->id,
            'workspace_id' => $attempt->workspaceId,
            'execution_intent_id' => $attempt->executionIntentId,
            'campaign_id' => $attempt->campaignId,
            'snapshot_id' => $attempt->snapshotId,
            'target_id' => $attempt->targetId,
            'target_hash' => $attempt->targetHash,
            'channel' => $attempt->channel,
            'provider_connection_id' => $attempt->providerConnectionId,
            'capability_evidence_id' => $attempt->capabilityEvidenceId,
            'provider_id' => $attempt->providerId,
            'idempotency_key' => $attempt->idempotencyKey,
            'state' => $attempt->state->value,
            'state_version' => $attempt->stateVersion,
            'attempt_hash' => $attempt->attemptHash,
            'created_at' => $attempt->createdAt,
            'updated_at' => $attempt->updatedAt,
        ]);

        if ($inserted === 1) {
            return $attempt;
        }

        $winner = $this->database->connection()->table('publication_attempts')
            ->where('workspace_id', $attempt->workspaceId)
            ->where(function ($query) use ($attempt): void {
                $query->where('id', $attempt->id)
                    ->orWhere('idempotency_key', $attempt->idempotencyKey)
                    ->orWhere(function ($identity) use ($attempt): void {
                        $identity->where('execution_intent_id', $attempt->executionIntentId)
                            ->where('target_id', $attempt->targetId);
                    });
            })
            ->lockForUpdate()
            ->first();

        if (! $winner instanceof stdClass) {
            $this->denyIfForeignAttemptIdExists($attempt->workspaceId, $attempt->id);
            throw new InvalidArgumentException('Publication attempt conflicts with existing canonical state.');
        }

        $stored = $this->hydrate($winner);
        $this->assertReplay($stored, $attempt);

        return $stored;
    }

    public function find(string $workspaceId, string $attemptId, bool $lock = false): ?PublicationAttempt
    {
        $query = $this->database->connection()->table('publication_attempts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $attemptId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();
        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignAttemptIdExists($workspaceId, $attemptId);

        return null;
    }

    private function assertAuthority(PublicationAttempt $attempt): void
    {
        $intent = $this->database->connection()->table('campaign_schedule_execution_intents')
            ->where('workspace_id', $attempt->workspaceId)
            ->where('id', $attempt->executionIntentId)
            ->lockForUpdate()
            ->first();

        if (! $intent instanceof stdClass) {
            if ($this->database->connection()->table('campaign_schedule_execution_intents')
                ->where('id', $attempt->executionIntentId)
                ->where('workspace_id', '<>', $attempt->workspaceId)
                ->exists()) {
                throw new AuthorizationException('Publication attempt execution intent access denied.');
            }

            throw new InvalidArgumentException('Publication attempt execution intent does not exist in this workspace.');
        }

        if (
            (string) $intent->campaign_id !== $attempt->campaignId
            || (string) $intent->snapshot_id !== $attempt->snapshotId
        ) {
            throw new InvalidArgumentException('Publication attempt execution intent authority does not match campaign snapshot.');
        }

        $target = $this->database->connection()->table('campaign_targets')
            ->where('workspace_id', $attempt->workspaceId)
            ->where('id', $attempt->targetId)
            ->lockForUpdate()
            ->first();

        if (! $target instanceof stdClass) {
            if ($this->database->connection()->table('campaign_targets')
                ->where('id', $attempt->targetId)
                ->where('workspace_id', '<>', $attempt->workspaceId)
                ->exists()) {
                throw new AuthorizationException('Publication attempt target access denied.');
            }

            throw new InvalidArgumentException('Publication attempt target does not exist in this workspace.');
        }

        if (
            (string) $target->snapshot_id !== $attempt->snapshotId
            || (string) $target->kind !== 'provider_connection'
            || (string) $target->channel !== $attempt->channel
            || (string) $target->provider_connection_id !== $attempt->providerConnectionId
            || (string) $target->capability_evidence_id !== $attempt->capabilityEvidenceId
            || ! hash_equals((string) $target->target_hash, $attempt->targetHash)
        ) {
            throw new InvalidArgumentException('Publication attempt target authority does not match immutable snapshot evidence.');
        }

        $connection = $this->database->connection()->table('provider_connections')
            ->where('workspace_id', $attempt->workspaceId)
            ->where('id', $attempt->providerConnectionId)
            ->lockForUpdate()
            ->first();
        $capability = $this->database->connection()->table('provider_capabilities')
            ->where('workspace_id', $attempt->workspaceId)
            ->where('id', $attempt->capabilityEvidenceId)
            ->lockForUpdate()
            ->first();

        if (! $connection instanceof stdClass || ! $capability instanceof stdClass) {
            throw new InvalidArgumentException('Publication attempt provider authority is unavailable.');
        }

        if (
            (string) $connection->provider_id !== $attempt->providerId
            || (string) $connection->readiness_status !== 'ready'
            || (string) $capability->provider_id !== $attempt->providerId
            || (string) $capability->connection_id !== $attempt->providerConnectionId
            || (string) $capability->operation !== 'publication.create'
            || (string) $capability->support_status !== 'supported'
            || $capability->source_version === null
            || trim((string) $capability->source_version) === ''
        ) {
            throw new InvalidArgumentException('Publication attempt current provider capability is not authorized.');
        }

        $at = $attempt->createdAt;
        $connectionObservedAt = $this->utc((string) $connection->observed_at);
        $capabilityObservedAt = $this->utc((string) $capability->observed_at);
        $connectionFreshUntil = $this->nullableUtc($connection->fresh_until);
        $tokenExpiresAt = $this->nullableUtc($connection->token_expires_at);
        $capabilityFreshUntil = $this->nullableUtc($capability->fresh_until);

        if (
            $connectionObservedAt > $at
            || $capabilityObservedAt > $at
            || ($connectionFreshUntil !== null && $connectionFreshUntil <= $at)
            || ($tokenExpiresAt !== null && $tokenExpiresAt <= $at)
            || ($capabilityFreshUntil !== null && $capabilityFreshUntil <= $at)
        ) {
            throw new InvalidArgumentException('Publication attempt current provider authority is stale or not yet effective.');
        }

        $requiredScopes = $this->decodeList((string) $capability->required_scopes);
        $requiredRoles = $this->decodeList((string) $capability->required_roles);
        $grantedScopes = $this->decodeList((string) $connection->granted_scopes);
        $roles = $this->decodeList((string) $connection->roles);

        if (array_diff($requiredScopes, $grantedScopes) !== [] || array_diff($requiredRoles, $roles) !== []) {
            throw new InvalidArgumentException('Publication attempt current provider scopes or roles are insufficient.');
        }
    }

    private function assertReplay(PublicationAttempt $stored, PublicationAttempt $candidate): void
    {
        if (
            $stored->workspaceId !== $candidate->workspaceId
            || $stored->executionIntentId !== $candidate->executionIntentId
            || $stored->campaignId !== $candidate->campaignId
            || $stored->snapshotId !== $candidate->snapshotId
            || $stored->targetId !== $candidate->targetId
            || $stored->providerConnectionId !== $candidate->providerConnectionId
            || $stored->capabilityEvidenceId !== $candidate->capabilityEvidenceId
            || $stored->providerId !== $candidate->providerId
            || ! hash_equals($stored->targetHash, $candidate->targetHash)
            || ! hash_equals($stored->idempotencyKey, $candidate->idempotencyKey)
            || ! hash_equals($stored->attemptHash, $candidate->attemptHash)
        ) {
            throw new InvalidArgumentException('Publication attempt replay conflicts with canonical authority evidence.');
        }
    }

    private function denyIfForeignAttemptIdExists(string $workspaceId, string $attemptId): void
    {
        if ($this->database->connection()->table('publication_attempts')
            ->where('id', $attemptId)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Publication attempt access denied.');
        }
    }

    private function hydrate(stdClass $row): PublicationAttempt
    {
        return new PublicationAttempt(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            executionIntentId: (string) $row->execution_intent_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            targetId: (string) $row->target_id,
            targetHash: (string) $row->target_hash,
            channel: (string) $row->channel,
            providerConnectionId: (string) $row->provider_connection_id,
            capabilityEvidenceId: (string) $row->capability_evidence_id,
            providerId: (string) $row->provider_id,
            idempotencyKey: (string) $row->idempotency_key,
            state: PublicationAttemptState::from((string) $row->state),
            stateVersion: (int) $row->state_version,
            attemptHash: (string) $row->attempt_hash,
            createdAt: $this->utc((string) $row->created_at),
            updatedAt: $this->utc((string) $row->updated_at),
        );
    }

    /** @return list<string> */
    private function decodeList(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_string($item)));
    }

    private function nullableUtc(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->utc((string) $value);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
