<?php

namespace App\Modules\Assets\Infrastructure\Persistence;

use App\Modules\Assets\Application\Transformation\VariantPlan;
use App\Modules\Assets\Domain\Asset\AssetKind;
use App\Modules\Assets\Domain\Asset\AssetLifecycle;
use App\Modules\Assets\Domain\Asset\AssetOriginal;
use App\Modules\Assets\Domain\Asset\CanonicalAsset;
use App\Modules\Assets\Domain\Ingestion\IngestionObservation;
use App\Modules\Assets\Domain\Variant\ProcessorIdentity;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final class DatabaseAssetRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    public function createAsset(CanonicalAsset $asset): CanonicalAsset
    {
        return $this->database->connection()->transaction(function () use ($asset): CanonicalAsset {
            $existing = $this->database->connection()->table('assets')->where('id', $asset->id)->lockForUpdate()->first();

            if ($existing instanceof stdClass) {
                if ((string) $existing->workspace_id !== $asset->workspaceId) {
                    throw new AuthorizationException('Canonical asset access denied.');
                }

                $stored = $this->hydrateAsset($existing);
                $this->assertAssetReplay($stored, $asset);

                return $stored;
            }

            $this->database->connection()->table('assets')->insert([
                'id' => $asset->id,
                'workspace_id' => $asset->workspaceId,
                'name' => $asset->name,
                'kind' => $asset->kind->value,
                'lifecycle' => $asset->lifecycle->value,
                'created_by_actor_id' => $asset->createdByActorId,
                'audit_provenance' => $this->encodeJson($asset->auditProvenance),
                'created_at' => $asset->createdAt,
                'updated_at' => $asset->updatedAt,
            ]);

            return $asset;
        });
    }

    public function findAsset(string $workspaceId, string $assetId): ?CanonicalAsset
    {
        $row = $this->database->connection()->table('assets')
            ->where('workspace_id', $workspaceId)
            ->where('id', $assetId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateAsset($row);
        }

        if ($this->database->connection()->table('assets')
            ->where('id', $assetId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Canonical asset access denied.');
        }

        return null;
    }

    public function appendOriginal(AssetOriginal $original): AssetOriginal
    {
        return $this->database->connection()->transaction(function () use ($original): AssetOriginal {
            $existingReplay = $this->database->connection()->table('asset_originals')
                ->where('workspace_id', $original->workspaceId)
                ->where('idempotency_key', $original->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingReplay instanceof stdClass) {
                $stored = $this->hydrateOriginal($existingReplay);
                $this->assertOriginalReplay($stored, $original);

                return $stored;
            }

            $this->assertAssetOwner($original);
            $this->assertOriginalIdAvailable($original);
            $this->assertOriginalLineage($original);

            $this->database->connection()->table('asset_originals')->insert([
                'id' => $original->id,
                'workspace_id' => $original->workspaceId,
                'asset_id' => $original->assetId,
                'parent_original_id' => $original->parentOriginalId,
                'version_number' => $original->versionNumber,
                'schema_version' => $original->schemaVersion,
                'content_sha256' => $original->observation->contentSha256,
                'observed_media_type' => $original->observation->observedMediaType,
                'byte_size' => $original->observation->byteSize,
                'width' => $original->observation->width,
                'height' => $original->observation->height,
                'duration_ms' => $original->observation->durationMs,
                'storage_disk' => $original->storageDisk,
                'storage_key' => $original->storageKey,
                'source_metadata' => $this->encodeJson($original->sourceMetadata),
                'rights_metadata' => $this->encodeJson($original->rightsMetadata),
                'created_by_actor_id' => $original->createdByActorId,
                'audit_provenance' => $this->encodeJson($original->auditProvenance),
                'idempotency_key' => $original->idempotencyKey,
                'created_at' => $original->createdAt,
            ]);

            return $original;
        });
    }

    public function findOriginal(string $workspaceId, string $originalId): ?AssetOriginal
    {
        $row = $this->database->connection()->table('asset_originals')
            ->where('workspace_id', $workspaceId)
            ->where('id', $originalId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateOriginal($row);
        }

        if ($this->database->connection()->table('asset_originals')
            ->where('id', $originalId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Asset original access denied.');
        }

        return null;
    }

    public function appendVariant(
        string $id,
        VariantPlan $plan,
        IngestionObservation $output,
        string $storageDisk,
        string $storageKey,
        array $auditProvenance,
        DateTimeImmutable $createdAt,
    ): StoredAssetVariant {
        return $this->database->connection()->transaction(function () use (
            $id,
            $plan,
            $output,
            $storageDisk,
            $storageKey,
            $auditProvenance,
            $createdAt,
        ): StoredAssetVariant {
            $this->assertSourceOriginal($plan->workspaceId, $plan->sourceOriginalId);

            $existingReplay = $this->database->connection()->table('asset_variants')
                ->where('workspace_id', $plan->workspaceId)
                ->where('idempotency_key', $plan->idempotencyKey)
                ->lockForUpdate()
                ->first();

            $candidate = new StoredAssetVariant(
                id: $id,
                workspaceId: $plan->workspaceId,
                sourceOriginalId: $plan->sourceOriginalId,
                schemaVersion: $plan->transformation->schemaVersion,
                transformationSpec: $plan->transformation->toArray(),
                transformationHash: $plan->transformation->hash(),
                processor: $plan->processor,
                output: $output,
                storageDisk: $storageDisk,
                storageKey: $storageKey,
                auditProvenance: $auditProvenance,
                idempotencyKey: $plan->idempotencyKey,
                createdAt: $createdAt,
            );

            if ($existingReplay instanceof stdClass) {
                $stored = $this->hydrateVariant($existingReplay);
                $this->assertVariantReplay($stored, $candidate);

                return $stored;
            }

            $existingRequest = $this->database->connection()->table('asset_variants')
                ->where('workspace_id', $plan->workspaceId)
                ->where('source_original_id', $plan->sourceOriginalId)
                ->where('transformation_hash', $candidate->transformationHash)
                ->where('processor_id', $plan->processor->id)
                ->where('processor_version', $plan->processor->version)
                ->lockForUpdate()
                ->first();

            if ($existingRequest instanceof stdClass) {
                $stored = $this->hydrateVariant($existingRequest);
                $this->assertEquivalentVariantOutput($stored, $candidate);

                return $stored;
            }

            $this->assertVariantIdAvailable($candidate);

            $this->database->connection()->table('asset_variants')->insert([
                'id' => $candidate->id,
                'workspace_id' => $candidate->workspaceId,
                'source_original_id' => $candidate->sourceOriginalId,
                'schema_version' => $candidate->schemaVersion,
                'transformation_spec' => $this->encodeJson($candidate->transformationSpec),
                'transformation_hash' => $candidate->transformationHash,
                'processor_id' => $candidate->processor->id,
                'processor_version' => $candidate->processor->version,
                'output_sha256' => $candidate->output->contentSha256,
                'observed_media_type' => $candidate->output->observedMediaType,
                'byte_size' => $candidate->output->byteSize,
                'width' => $candidate->output->width,
                'height' => $candidate->output->height,
                'duration_ms' => $candidate->output->durationMs,
                'storage_disk' => $candidate->storageDisk,
                'storage_key' => $candidate->storageKey,
                'audit_provenance' => $this->encodeJson($candidate->auditProvenance),
                'idempotency_key' => $candidate->idempotencyKey,
                'created_at' => $candidate->createdAt,
            ]);

            return $candidate;
        });
    }

    public function findVariant(string $workspaceId, string $variantId): ?StoredAssetVariant
    {
        $row = $this->database->connection()->table('asset_variants')
            ->where('workspace_id', $workspaceId)
            ->where('id', $variantId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateVariant($row);
        }

        if ($this->database->connection()->table('asset_variants')
            ->where('id', $variantId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Asset variant access denied.');
        }

        return null;
    }

    private function assertAssetOwner(AssetOriginal $original): void
    {
        $row = $this->database->connection()->table('assets')->where('id', $original->assetId)->first();

        if (! $row instanceof stdClass) {
            throw new InvalidArgumentException('Canonical asset does not exist.');
        }

        if ((string) $row->workspace_id !== $original->workspaceId) {
            throw new AuthorizationException('Canonical asset access denied.');
        }
    }

    private function assertOriginalIdAvailable(AssetOriginal $original): void
    {
        $row = $this->database->connection()->table('asset_originals')->where('id', $original->id)->first();

        if (! $row instanceof stdClass) {
            return;
        }

        if ((string) $row->workspace_id !== $original->workspaceId) {
            throw new AuthorizationException('Asset original access denied.');
        }

        throw new InvalidArgumentException('Asset original ID already exists with a different idempotency key.');
    }

    private function assertOriginalLineage(AssetOriginal $original): void
    {
        if ($original->parentOriginalId === null) {
            if ($original->versionNumber !== 1) {
                throw new InvalidArgumentException('Initial asset original must use version number 1.');
            }

            return;
        }

        $parent = $this->database->connection()->table('asset_originals')
            ->where('id', $original->parentOriginalId)
            ->first();

        if (! $parent instanceof stdClass) {
            throw new InvalidArgumentException('Asset original parent does not exist.');
        }

        if ((string) $parent->workspace_id !== $original->workspaceId) {
            throw new AuthorizationException('Asset original parent access denied.');
        }

        if ((string) $parent->asset_id !== $original->assetId) {
            throw new InvalidArgumentException('Asset original parent belongs to a different canonical asset.');
        }

        if ((int) $parent->version_number + 1 !== $original->versionNumber) {
            throw new InvalidArgumentException('Asset original lineage must advance exactly one version.');
        }

        if (new DateTimeImmutable((string) $parent->created_at) > $original->createdAt) {
            throw new InvalidArgumentException('Asset original cannot precede its parent.');
        }
    }

    private function assertSourceOriginal(string $workspaceId, string $originalId): void
    {
        $row = $this->database->connection()->table('asset_originals')->where('id', $originalId)->first();

        if (! $row instanceof stdClass) {
            throw new InvalidArgumentException('Asset variant source original does not exist.');
        }

        if ((string) $row->workspace_id !== $workspaceId) {
            throw new AuthorizationException('Asset variant source original access denied.');
        }
    }

    private function assertVariantIdAvailable(StoredAssetVariant $variant): void
    {
        $row = $this->database->connection()->table('asset_variants')->where('id', $variant->id)->first();

        if (! $row instanceof stdClass) {
            return;
        }

        if ((string) $row->workspace_id !== $variant->workspaceId) {
            throw new AuthorizationException('Asset variant access denied.');
        }

        throw new InvalidArgumentException('Asset variant ID already exists with a different replay identity.');
    }

    private function assertAssetReplay(CanonicalAsset $stored, CanonicalAsset $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->name !== $candidate->name
            || $stored->kind !== $candidate->kind
            || $stored->lifecycle !== $candidate->lifecycle
            || $stored->createdByActorId !== $candidate->createdByActorId
            || $stored->auditProvenance !== $candidate->auditProvenance
            || $stored->createdAt != $candidate->createdAt
            || $stored->updatedAt != $candidate->updatedAt
        ) {
            throw new InvalidArgumentException('Canonical asset ID conflicts with different state.');
        }
    }

    private function assertOriginalReplay(AssetOriginal $stored, AssetOriginal $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->assetId !== $candidate->assetId
            || $stored->parentOriginalId !== $candidate->parentOriginalId
            || $stored->versionNumber !== $candidate->versionNumber
            || $stored->schemaVersion !== $candidate->schemaVersion
            || $stored->observation != $candidate->observation
            || $stored->storageDisk !== $candidate->storageDisk
            || $stored->storageKey !== $candidate->storageKey
            || $stored->sourceMetadata !== $candidate->sourceMetadata
            || $stored->rightsMetadata !== $candidate->rightsMetadata
            || $stored->createdByActorId !== $candidate->createdByActorId
            || $stored->auditProvenance !== $candidate->auditProvenance
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || $stored->createdAt != $candidate->createdAt
        ) {
            throw new InvalidArgumentException('Asset original idempotency key conflicts with different immutable state.');
        }
    }

    private function assertVariantReplay(StoredAssetVariant $stored, StoredAssetVariant $candidate): void
    {
        if ($stored->id !== $candidate->id || $stored->idempotencyKey !== $candidate->idempotencyKey) {
            throw new InvalidArgumentException('Asset variant idempotency key conflicts with different replay identity.');
        }

        $this->assertEquivalentVariantOutput($stored, $candidate);
    }

    private function assertEquivalentVariantOutput(StoredAssetVariant $stored, StoredAssetVariant $candidate): void
    {
        if (
            $stored->workspaceId !== $candidate->workspaceId
            || $stored->sourceOriginalId !== $candidate->sourceOriginalId
            || $stored->schemaVersion !== $candidate->schemaVersion
            || $stored->transformationSpec !== $candidate->transformationSpec
            || $stored->transformationHash !== $candidate->transformationHash
            || $stored->processor != $candidate->processor
            || $stored->output != $candidate->output
            || $stored->storageDisk !== $candidate->storageDisk
            || $stored->storageKey !== $candidate->storageKey
            || $stored->auditProvenance !== $candidate->auditProvenance
            || $stored->createdAt != $candidate->createdAt
        ) {
            throw new InvalidArgumentException('Equivalent asset variant request conflicts with different persisted output.');
        }
    }

    private function hydrateAsset(stdClass $row): CanonicalAsset
    {
        return new CanonicalAsset(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            name: (string) $row->name,
            kind: AssetKind::from((string) $row->kind),
            lifecycle: AssetLifecycle::from((string) $row->lifecycle),
            createdByActorId: (string) $row->created_by_actor_id,
            auditProvenance: $this->decodeArray($row->audit_provenance, 'asset audit provenance'),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: $row->updated_at === null ? null : new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function hydrateOriginal(stdClass $row): AssetOriginal
    {
        return new AssetOriginal(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            assetId: (string) $row->asset_id,
            parentOriginalId: $row->parent_original_id === null ? null : (string) $row->parent_original_id,
            versionNumber: (int) $row->version_number,
            schemaVersion: (int) $row->schema_version,
            observation: new IngestionObservation(
                contentSha256: (string) $row->content_sha256,
                observedMediaType: (string) $row->observed_media_type,
                byteSize: (int) $row->byte_size,
                width: $row->width === null ? null : (int) $row->width,
                height: $row->height === null ? null : (int) $row->height,
                durationMs: $row->duration_ms === null ? null : (int) $row->duration_ms,
            ),
            storageDisk: (string) $row->storage_disk,
            storageKey: (string) $row->storage_key,
            sourceMetadata: $this->decodeArray($row->source_metadata, 'asset source metadata'),
            rightsMetadata: $this->decodeArray($row->rights_metadata, 'asset rights metadata'),
            createdByActorId: (string) $row->created_by_actor_id,
            auditProvenance: $this->decodeArray($row->audit_provenance, 'asset original audit provenance'),
            idempotencyKey: (string) $row->idempotency_key,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function hydrateVariant(stdClass $row): StoredAssetVariant
    {
        return new StoredAssetVariant(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            sourceOriginalId: (string) $row->source_original_id,
            schemaVersion: (int) $row->schema_version,
            transformationSpec: $this->decodeArray($row->transformation_spec, 'asset transformation spec'),
            transformationHash: (string) $row->transformation_hash,
            processor: new ProcessorIdentity(
                id: (string) $row->processor_id,
                version: (string) $row->processor_version,
            ),
            output: new IngestionObservation(
                contentSha256: (string) $row->output_sha256,
                observedMediaType: (string) $row->observed_media_type,
                byteSize: (int) $row->byte_size,
                width: $row->width === null ? null : (int) $row->width,
                height: $row->height === null ? null : (int) $row->height,
                durationMs: $row->duration_ms === null ? null : (int) $row->duration_ms,
            ),
            storageDisk: (string) $row->storage_disk,
            storageKey: (string) $row->storage_key,
            auditProvenance: $this->decodeArray($row->audit_provenance, 'asset variant audit provenance'),
            idempotencyKey: (string) $row->idempotency_key,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Asset persistence payload cannot be encoded as JSON.', previous: $exception);
        }
    }

    private function decodeArray(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException("Stored {$field} must be JSON.");
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("Stored {$field} contains invalid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("Stored {$field} must decode to an array.");
        }

        return $decoded;
    }
}
