<?php

namespace App\Modules\Consent\Infrastructure\Suppression;

use App\Modules\Consent\Domain\Suppression\PreferenceDecision;
use App\Modules\Consent\Domain\Suppression\PreferenceRecord;
use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseSuppressionRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function appendSuppression(SuppressionRecord $record): SuppressionRecord
    {
        return $this->database->connection()->transaction(function () use ($record): SuppressionRecord {
            $this->assertContactScope($record->workspaceId, $record->contactId);

            $existing = $this->database->connection()->table('suppression_records')
                ->where('workspace_id', $record->workspaceId)
                ->where('idempotency_key', $record->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateSuppression($existing);
                $this->assertSuppressionReplay($stored, $record);

                return $stored;
            }

            $this->denyIfForeignSuppressionIdExists($record->workspaceId, $record->id);

            try {
                $this->database->connection()->table('suppression_records')->insert([
                    'id' => $record->id,
                    'workspace_id' => $record->workspaceId,
                    'contact_id' => $record->contactId,
                    'channel' => $record->channel,
                    'purpose' => $record->purpose,
                    'authority_type' => $record->authorityType->value,
                    'source_type' => $record->sourceType,
                    'source_version' => $record->sourceVersion,
                    'provider_key' => $record->providerKey,
                    'idempotency_key' => $record->idempotencyKey,
                    'evidence_fingerprint' => $record->evidenceFingerprint,
                    'observed_at' => $record->observedAt,
                    'effective_at' => $record->effectiveAt,
                    'fresh_until' => $record->freshUntil,
                    'immutable_evidence' => $this->encode($record->immutableEvidence),
                    'metadata' => $this->encode($record->metadata),
                    'created_at' => $record->effectiveAt,
                    'updated_at' => $record->effectiveAt,
                ]);
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('suppression_records')
                    ->where('workspace_id', $record->workspaceId)
                    ->where('idempotency_key', $record->idempotencyKey)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $stored = $this->hydrateSuppression($raceWinner);
                    $this->assertSuppressionReplay($stored, $record);

                    return $stored;
                }

                throw $exception;
            }

            return $record;
        });
    }

    public function isSuppressed(
        string $workspaceId,
        string $contactId,
        string $channel,
        string $purpose,
        ?DateTimeImmutable $at = null,
    ): bool {
        $effectiveAt = $at ?? new DateTimeImmutable;

        return $this->database->connection()->table('suppression_records')
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('effective_at', '<=', $effectiveAt)
            ->exists();
    }

    public function appendPreference(PreferenceRecord $record): PreferenceRecord
    {
        return $this->database->connection()->transaction(function () use ($record): PreferenceRecord {
            $this->assertContactScope($record->workspaceId, $record->contactId);

            $existing = $this->database->connection()->table('preference_records')
                ->where('workspace_id', $record->workspaceId)
                ->where('idempotency_key', $record->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydratePreference($existing);
                $this->assertPreferenceReplay($stored, $record);

                return $stored;
            }

            $this->denyIfForeignPreferenceIdExists($record->workspaceId, $record->id);

            try {
                $this->database->connection()->table('preference_records')->insert([
                    'id' => $record->id,
                    'workspace_id' => $record->workspaceId,
                    'contact_id' => $record->contactId,
                    'channel' => $record->channel,
                    'purpose' => $record->purpose,
                    'scope_type' => $record->scopeType,
                    'scope_key' => $record->scopeKey,
                    'decision' => $record->decision->value,
                    'basis_type' => $record->basisType,
                    'source_type' => $record->sourceType,
                    'source_version' => $record->sourceVersion,
                    'idempotency_key' => $record->idempotencyKey,
                    'evidence_fingerprint' => $record->evidenceFingerprint,
                    'observed_at' => $record->observedAt,
                    'effective_at' => $record->effectiveAt,
                    'immutable_evidence' => $this->encode($record->immutableEvidence),
                    'metadata' => $this->encode($record->metadata),
                    'created_at' => $record->effectiveAt,
                    'updated_at' => $record->effectiveAt,
                ]);
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('preference_records')
                    ->where('workspace_id', $record->workspaceId)
                    ->where('idempotency_key', $record->idempotencyKey)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $stored = $this->hydratePreference($raceWinner);
                    $this->assertPreferenceReplay($stored, $record);

                    return $stored;
                }

                throw $exception;
            }

            return $record;
        });
    }

    public function latestPreference(
        string $workspaceId,
        string $contactId,
        string $channel,
        string $purpose,
        string $scopeType,
        ?string $scopeKey,
    ): ?PreferenceRecord {
        $query = $this->database->connection()->table('preference_records')
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('scope_type', $scopeType);

        $scopeKey === null ? $query->whereNull('scope_key') : $query->where('scope_key', $scopeKey);

        $row = $query
            ->orderByDesc('effective_at')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        return $row instanceof stdClass ? $this->hydratePreference($row) : null;
    }

    private function assertContactScope(string $workspaceId, string $contactId): void
    {
        $scoped = $this->database->connection()->table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $contactId)
            ->exists();

        if ($scoped) {
            return;
        }

        if ($this->database->connection()->table('contacts')->where('id', $contactId)->exists()) {
            throw new AuthorizationException('Contact access denied.');
        }

        throw new InvalidArgumentException('Contact does not exist in this workspace.');
    }

    private function denyIfForeignSuppressionIdExists(string $workspaceId, string $id): void
    {
        $row = $this->database->connection()->table('suppression_records')->where('id', $id)->first();
        if ($row instanceof stdClass) {
            if ((string) $row->workspace_id !== $workspaceId) {
                throw new AuthorizationException('Suppression record access denied.');
            }

            throw new InvalidArgumentException('Suppression record ID already exists in this workspace.');
        }
    }

    private function denyIfForeignPreferenceIdExists(string $workspaceId, string $id): void
    {
        $row = $this->database->connection()->table('preference_records')->where('id', $id)->first();
        if ($row instanceof stdClass) {
            if ((string) $row->workspace_id !== $workspaceId) {
                throw new AuthorizationException('Preference record access denied.');
            }

            throw new InvalidArgumentException('Preference record ID already exists in this workspace.');
        }
    }

    private function assertSuppressionReplay(SuppressionRecord $stored, SuppressionRecord $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->contactId !== $candidate->contactId
            || $stored->channel !== $candidate->channel
            || $stored->purpose !== $candidate->purpose
            || $stored->authorityType !== $candidate->authorityType
            || $stored->sourceType !== $candidate->sourceType
            || $stored->sourceVersion !== $candidate->sourceVersion
            || $stored->providerKey !== $candidate->providerKey
            || $stored->evidenceFingerprint !== $candidate->evidenceFingerprint
            || $stored->observedAt != $candidate->observedAt
            || $stored->effectiveAt != $candidate->effectiveAt
            || $stored->freshUntil != $candidate->freshUntil
        ) {
            throw new InvalidArgumentException('Suppression idempotency key conflicts with different evidence.');
        }
    }

    private function assertPreferenceReplay(PreferenceRecord $stored, PreferenceRecord $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->contactId !== $candidate->contactId
            || $stored->channel !== $candidate->channel
            || $stored->purpose !== $candidate->purpose
            || $stored->scopeType !== $candidate->scopeType
            || $stored->scopeKey !== $candidate->scopeKey
            || $stored->decision !== $candidate->decision
            || $stored->basisType !== $candidate->basisType
            || $stored->sourceType !== $candidate->sourceType
            || $stored->sourceVersion !== $candidate->sourceVersion
            || $stored->evidenceFingerprint !== $candidate->evidenceFingerprint
            || $stored->observedAt != $candidate->observedAt
            || $stored->effectiveAt != $candidate->effectiveAt
        ) {
            throw new InvalidArgumentException('Preference idempotency key conflicts with different evidence.');
        }
    }

    private function hydrateSuppression(stdClass $row): SuppressionRecord
    {
        return new SuppressionRecord(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            contactId: (string) $row->contact_id,
            channel: (string) $row->channel,
            purpose: (string) $row->purpose,
            authorityType: SuppressionAuthorityType::from((string) $row->authority_type),
            sourceType: (string) $row->source_type,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            providerKey: $row->provider_key === null ? null : (string) $row->provider_key,
            idempotencyKey: (string) $row->idempotency_key,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            effectiveAt: new DateTimeImmutable((string) $row->effective_at),
            freshUntil: $row->fresh_until === null ? null : new DateTimeImmutable((string) $row->fresh_until),
            immutableEvidence: $this->decode((string) $row->immutable_evidence),
            metadata: $this->decode((string) $row->metadata),
        );
    }

    private function hydratePreference(stdClass $row): PreferenceRecord
    {
        return new PreferenceRecord(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            contactId: (string) $row->contact_id,
            channel: (string) $row->channel,
            purpose: (string) $row->purpose,
            scopeType: (string) $row->scope_type,
            scopeKey: $row->scope_key === null ? null : (string) $row->scope_key,
            decision: PreferenceDecision::from((string) $row->decision),
            basisType: $row->basis_type === null ? null : (string) $row->basis_type,
            sourceType: (string) $row->source_type,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            idempotencyKey: (string) $row->idempotency_key,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            effectiveAt: new DateTimeImmutable((string) $row->effective_at),
            immutableEvidence: $this->decode((string) $row->immutable_evidence),
            metadata: $this->decode((string) $row->metadata),
        );
    }

    /** @throws JsonException */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string|int, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Stored suppression evidence must decode to an array.');
        }

        return $decoded;
    }
}
