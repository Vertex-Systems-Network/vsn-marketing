<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Publication\ProviderMediaAssetReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReference;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceState;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseProviderMediaReferenceRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function create(ProviderMediaReference $reference): ProviderMediaReference
    {
        $this->assertAuthority($reference);

        $existing = $this->database->connection()->table('publication_media_references')
            ->where('workspace_id', $reference->workspaceId)
            ->where('idempotency_key', $reference->idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof stdClass) {
            $stored = $this->hydrate($existing);
            $this->assertReplay($stored, $reference);

            return $stored;
        }

        $inserted = $this->database->connection()->table('publication_media_references')->insertOrIgnore([
            'id' => $reference->id,
            'workspace_id' => $reference->workspaceId,
            'publication_attempt_id' => $reference->publicationAttemptId,
            'snapshot_id' => $reference->snapshotId,
            'asset_id' => $reference->assetId,
            'asset_original_id' => $reference->assetOriginalId,
            'asset_variant_id' => $reference->assetVariantId,
            'asset_reference_kind' => $reference->assetReferenceKind->value,
            'canonical_asset_reference_id' => $reference->canonicalAssetReferenceId,
            'asset_content_sha256' => $reference->assetContentSha256,
            'provider_connection_id' => $reference->providerConnectionId,
            'capability_evidence_id' => $reference->capabilityEvidenceId,
            'provider_id' => $reference->providerId,
            'provider_reference_kind' => $reference->providerReferenceKind->value,
            'provider_reference' => $reference->providerReference,
            'state' => $reference->state->value,
            'state_version' => $reference->stateVersion,
            'expires_at' => $reference->expiresAt,
            'idempotency_key' => $reference->idempotencyKey,
            'reference_hash' => $reference->referenceHash,
            'created_at' => $reference->createdAt,
            'updated_at' => $reference->updatedAt,
        ]);

        if ($inserted === 1) {
            return $reference;
        }

        $winner = $this->database->connection()->table('publication_media_references')
            ->where('workspace_id', $reference->workspaceId)
            ->where(function ($query) use ($reference): void {
                $query->where('id', $reference->id)
                    ->orWhere('idempotency_key', $reference->idempotencyKey)
                    ->orWhere(function ($provider) use ($reference): void {
                        $provider->where('provider_connection_id', $reference->providerConnectionId)
                            ->where('provider_reference_kind', $reference->providerReferenceKind->value)
                            ->where('provider_reference', $reference->providerReference);
                    });
            })
            ->lockForUpdate()
            ->first();

        if (! $winner instanceof stdClass) {
            $this->denyIfForeignReferenceIdExists($reference->workspaceId, $reference->id);
            throw new InvalidArgumentException('Provider media reference conflicts with canonical derivative state.');
        }

        $stored = $this->hydrate($winner);
        $this->assertReplay($stored, $reference);

        return $stored;
    }

    public function find(string $workspaceId, string $referenceId, bool $lock = false): ?ProviderMediaReference
    {
        $query = $this->database->connection()->table('publication_media_references')
            ->where('workspace_id', $workspaceId)
            ->where('id', $referenceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();
        if ($row instanceof stdClass) {
            return $this->hydrate($row);
        }

        $this->denyIfForeignReferenceIdExists($workspaceId, $referenceId);

        return null;
    }

    public function transition(ProviderMediaReference $reference, int $expectedVersion): ProviderMediaReference
    {
        if ($reference->stateVersion !== $expectedVersion + 1) {
            throw new InvalidArgumentException('Provider media reference transition version is not contiguous.');
        }

        $updated = $this->database->connection()->table('publication_media_references')
            ->where('workspace_id', $reference->workspaceId)
            ->where('id', $reference->id)
            ->where('state_version', $expectedVersion)
            ->update([
                'state' => $reference->state->value,
                'state_version' => $reference->stateVersion,
                'updated_at' => $reference->updatedAt,
            ]);

        if ($updated !== 1) {
            throw new InvalidArgumentException('Provider media reference transition lost optimistic concurrency.');
        }

        return $reference;
    }

    private function assertAuthority(ProviderMediaReference $reference): void
    {
        $attempt = $this->database->connection()->table('publication_attempts')
            ->where('workspace_id', $reference->workspaceId)
            ->where('id', $reference->publicationAttemptId)
            ->lockForUpdate()
            ->first();

        if (! $attempt instanceof stdClass) {
            if ($this->database->connection()->table('publication_attempts')
                ->where('id', $reference->publicationAttemptId)
                ->where('workspace_id', '<>', $reference->workspaceId)
                ->exists()) {
                throw new AuthorizationException('Provider media publication attempt access denied.');
            }

            throw new InvalidArgumentException('Provider media publication attempt does not exist in this workspace.');
        }

        if (
            (string) $attempt->snapshot_id !== $reference->snapshotId
            || (string) $attempt->provider_connection_id !== $reference->providerConnectionId
            || (string) $attempt->capability_evidence_id !== $reference->capabilityEvidenceId
            || (string) $attempt->provider_id !== $reference->providerId
        ) {
            throw new InvalidArgumentException('Provider media reference is not bound to exact publication-attempt authority.');
        }

        $snapshot = $this->database->connection()->table('campaign_snapshots')
            ->where('workspace_id', $reference->workspaceId)
            ->where('id', $reference->snapshotId)
            ->lockForUpdate()
            ->first();

        if (! $snapshot instanceof stdClass) {
            throw new InvalidArgumentException('Provider media reference snapshot authority is unavailable.');
        }

        $assetReferences = $this->decodeList((string) $snapshot->asset_reference_ids);
        if (! in_array($reference->canonicalAssetReferenceId, $assetReferences, true)) {
            throw new InvalidArgumentException('Provider media reference asset version is not pinned by the immutable campaign snapshot.');
        }

        $original = $this->database->connection()->table('asset_originals')
            ->where('workspace_id', $reference->workspaceId)
            ->where('id', $reference->assetOriginalId)
            ->lockForUpdate()
            ->first();

        if (! $original instanceof stdClass || (string) $original->asset_id !== $reference->assetId) {
            throw new InvalidArgumentException('Provider media reference canonical asset original authority is unavailable.');
        }

        if ($reference->assetReferenceKind === ProviderMediaAssetReferenceKind::Original) {
            if (
                $reference->assetVariantId !== null
                || $reference->canonicalAssetReferenceId !== $reference->assetOriginalId
                || ! hash_equals((string) $original->content_sha256, $reference->assetContentSha256)
            ) {
                throw new InvalidArgumentException('Provider media original identity does not match canonical asset evidence.');
            }
        } else {
            $variant = $this->database->connection()->table('asset_variants')
                ->where('workspace_id', $reference->workspaceId)
                ->where('id', $reference->assetVariantId)
                ->lockForUpdate()
                ->first();

            if (
                ! $variant instanceof stdClass
                || (string) $variant->source_original_id !== $reference->assetOriginalId
                || $reference->canonicalAssetReferenceId !== (string) $variant->id
                || ! hash_equals((string) $variant->output_sha256, $reference->assetContentSha256)
            ) {
                throw new InvalidArgumentException('Provider media variant identity does not match canonical asset evidence.');
            }
        }

        $connection = $this->database->connection()->table('provider_connections')
            ->where('workspace_id', $reference->workspaceId)
            ->where('id', $reference->providerConnectionId)
            ->lockForUpdate()
            ->first();
        $capability = $this->database->connection()->table('provider_capabilities')
            ->where('workspace_id', $reference->workspaceId)
            ->where('id', $reference->capabilityEvidenceId)
            ->lockForUpdate()
            ->first();

        if (! $connection instanceof stdClass || ! $capability instanceof stdClass) {
            throw new InvalidArgumentException('Provider media current provider authority is unavailable.');
        }

        if (
            (string) $connection->provider_id !== $reference->providerId
            || (string) $connection->readiness_status !== 'ready'
            || (string) $capability->provider_id !== $reference->providerId
            || (string) $capability->connection_id !== $reference->providerConnectionId
            || (string) $capability->operation !== 'publication.create'
            || (string) $capability->support_status !== 'supported'
            || $capability->source_version === null
            || trim((string) $capability->source_version) === ''
        ) {
            throw new InvalidArgumentException('Provider media current provider capability is not authorized.');
        }

        $at = $reference->createdAt;
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
            throw new InvalidArgumentException('Provider media current provider authority is stale or not yet effective.');
        }

        $requiredScopes = $this->decodeList((string) $capability->required_scopes);
        $requiredRoles = $this->decodeList((string) $capability->required_roles);
        $grantedScopes = $this->decodeList((string) $connection->granted_scopes);
        $roles = $this->decodeList((string) $connection->roles);

        if (array_diff($requiredScopes, $grantedScopes) !== [] || array_diff($requiredRoles, $roles) !== []) {
            throw new InvalidArgumentException('Provider media current provider scopes or roles are insufficient.');
        }
    }

    private function assertReplay(ProviderMediaReference $stored, ProviderMediaReference $candidate): void
    {
        if (
            $stored->workspaceId !== $candidate->workspaceId
            || $stored->publicationAttemptId !== $candidate->publicationAttemptId
            || $stored->snapshotId !== $candidate->snapshotId
            || $stored->assetId !== $candidate->assetId
            || $stored->assetOriginalId !== $candidate->assetOriginalId
            || $stored->assetVariantId !== $candidate->assetVariantId
            || $stored->assetReferenceKind !== $candidate->assetReferenceKind
            || $stored->canonicalAssetReferenceId !== $candidate->canonicalAssetReferenceId
            || ! hash_equals($stored->assetContentSha256, $candidate->assetContentSha256)
            || $stored->providerConnectionId !== $candidate->providerConnectionId
            || $stored->capabilityEvidenceId !== $candidate->capabilityEvidenceId
            || $stored->providerId !== $candidate->providerId
            || $stored->providerReferenceKind !== $candidate->providerReferenceKind
            || $stored->providerReference !== $candidate->providerReference
            || $stored->expiresAt != $candidate->expiresAt
            || ! hash_equals($stored->idempotencyKey, $candidate->idempotencyKey)
            || ! hash_equals($stored->referenceHash, $candidate->referenceHash)
        ) {
            throw new InvalidArgumentException('Provider media reference replay conflicts with canonical derivative authority.');
        }
    }

    private function denyIfForeignReferenceIdExists(string $workspaceId, string $referenceId): void
    {
        if ($this->database->connection()->table('publication_media_references')
            ->where('id', $referenceId)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Provider media reference access denied.');
        }
    }

    private function hydrate(stdClass $row): ProviderMediaReference
    {
        return new ProviderMediaReference(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            publicationAttemptId: (string) $row->publication_attempt_id,
            snapshotId: (string) $row->snapshot_id,
            assetId: (string) $row->asset_id,
            assetOriginalId: (string) $row->asset_original_id,
            assetVariantId: $row->asset_variant_id === null ? null : (string) $row->asset_variant_id,
            assetReferenceKind: ProviderMediaAssetReferenceKind::from((string) $row->asset_reference_kind),
            canonicalAssetReferenceId: (string) $row->canonical_asset_reference_id,
            assetContentSha256: (string) $row->asset_content_sha256,
            providerConnectionId: (string) $row->provider_connection_id,
            capabilityEvidenceId: (string) $row->capability_evidence_id,
            providerId: (string) $row->provider_id,
            providerReferenceKind: ProviderMediaReferenceKind::from((string) $row->provider_reference_kind),
            providerReference: (string) $row->provider_reference,
            state: ProviderMediaReferenceState::from((string) $row->state),
            stateVersion: (int) $row->state_version,
            expiresAt: $this->nullableUtc($row->expires_at),
            idempotencyKey: (string) $row->idempotency_key,
            referenceHash: (string) $row->reference_hash,
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
