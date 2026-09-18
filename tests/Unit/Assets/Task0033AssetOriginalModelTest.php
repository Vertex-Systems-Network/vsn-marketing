<?php

use App\Modules\Assets\Domain\Asset\AssetKind;
use App\Modules\Assets\Domain\Asset\AssetLifecycle;
use App\Modules\Assets\Domain\Asset\AssetOriginal;
use App\Modules\Assets\Domain\Asset\CanonicalAsset;
use App\Modules\Assets\Domain\Ingestion\IngestionObservation;
use App\Modules\Assets\Domain\Ingestion\IngestionPolicy;

function task0033Asset(): CanonicalAsset
{
    return new CanonicalAsset(
        id: 'asset-1',
        workspaceId: 'workspace-1',
        name: 'Hero image',
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Draft,
        createdByActorId: 'user-1',
        auditProvenance: ['source' => 'upload'],
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );
}

function task0033Observation(string $hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): IngestionObservation
{
    return new IngestionObservation(
        contentSha256: $hash,
        observedMediaType: 'image/png',
        byteSize: 2048,
        width: 1200,
        height: 630,
    );
}

it('models a workspace-scoped canonical asset with monotonic lifecycle', function () {
    $asset = task0033Asset();
    $active = $asset->transitionTo(AssetLifecycle::Active, new DateTimeImmutable('2026-09-19T00:01:00+00:00'));
    $archived = $active->transitionTo(AssetLifecycle::Archived, new DateTimeImmutable('2026-09-19T00:02:00+00:00'));

    expect($archived->id)->toBe('asset-1')
        ->and($archived->workspaceId)->toBe('workspace-1')
        ->and($archived->kind)->toBe(AssetKind::Image)
        ->and($archived->lifecycle)->toBe(AssetLifecycle::Archived);

    expect(fn () => $archived->transitionTo(AssetLifecycle::Active, new DateTimeImmutable('2026-09-19T00:03:00+00:00')))
        ->toThrow(InvalidArgumentException::class, 'Invalid canonical asset lifecycle transition');
});

it('validates observed content against bounded ingestion policy and declarations', function () {
    $observation = task0033Observation();
    $policy = new IngestionPolicy(
        maxBytes: 5_000_000,
        allowedMediaTypes: ['image/*', 'application/pdf'],
    );

    $observation->assertAcceptedBy(
        policy: $policy,
        kind: AssetKind::Image,
        declaredMediaType: 'image/png',
        declaredByteSize: 2048,
    );

    expect(fn () => $observation->assertAcceptedBy(
        policy: $policy,
        kind: AssetKind::Image,
        declaredMediaType: 'image/jpeg',
    ))->toThrow(InvalidArgumentException::class, 'Declared asset media type does not match observed content');

    expect(fn () => task0033Observation()->assertAcceptedBy(
        policy: new IngestionPolicy(maxBytes: 1024, allowedMediaTypes: ['image/*']),
        kind: AssetKind::Image,
    ))->toThrow(InvalidArgumentException::class, 'exceeds the configured ingestion size limit');
});

it('creates immutable original lineage instead of overwriting binary history', function () {
    $first = AssetOriginal::initialFor(
        asset: task0033Asset(),
        id: 'original-1',
        observation: task0033Observation(),
        storageDisk: 's3',
        storageKey: 'workspaces/workspace-1/assets/original-1/source.png',
        sourceMetadata: ['origin' => 'operator-upload'],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'user-1',
        auditProvenance: ['request_id' => 'req-1'],
        idempotencyKey: 'upload-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );

    $second = $first->replaceWith(
        id: 'original-2',
        observation: task0033Observation('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
        storageDisk: 's3',
        storageKey: 'workspaces/workspace-1/assets/original-2/source.png',
        sourceMetadata: ['origin' => 'replacement-upload'],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'user-2',
        auditProvenance: ['request_id' => 'req-2'],
        idempotencyKey: 'upload-2',
        createdAt: new DateTimeImmutable('2026-09-19T00:05:00+00:00'),
    );

    expect($first->versionNumber)->toBe(1)
        ->and($first->parentOriginalId)->toBeNull()
        ->and($second->versionNumber)->toBe(2)
        ->and($second->parentOriginalId)->toBe('original-1')
        ->and($second->assetId)->toBe($first->assetId)
        ->and($second->observation->contentSha256)->not->toBe($first->observation->contentSha256);
});

it('rejects unsafe storage identities malformed hashes and sensitive provenance', function () {
    expect(fn () => task0033Observation('not-a-sha'))
        ->toThrow(InvalidArgumentException::class, 'lowercase SHA-256');

    expect(fn () => AssetOriginal::initialFor(
        asset: task0033Asset(),
        id: 'original-1',
        observation: task0033Observation(),
        storageDisk: 's3',
        storageKey: '../secret.png',
        sourceMetadata: [],
        rightsMetadata: [],
        createdByActorId: 'user-1',
        auditProvenance: [],
        idempotencyKey: 'upload-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'unsafe path');

    expect(fn () => new CanonicalAsset(
        id: 'asset-1',
        workspaceId: 'workspace-1',
        name: 'Unsafe',
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Draft,
        createdByActorId: 'user-1',
        auditProvenance: ['nested' => ['api_key' => 'forbidden']],
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive asset metadata key is forbidden');
});
