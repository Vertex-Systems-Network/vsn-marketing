<?php

use App\Modules\Assets\Application\Transformation\VariantPlan;
use App\Modules\Assets\Domain\Asset\AssetKind;
use App\Modules\Assets\Domain\Asset\AssetLifecycle;
use App\Modules\Assets\Domain\Asset\AssetOriginal;
use App\Modules\Assets\Domain\Asset\CanonicalAsset;
use App\Modules\Assets\Domain\Ingestion\IngestionObservation;
use App\Modules\Assets\Domain\Variant\ProcessorIdentity;
use App\Modules\Assets\Domain\Variant\TransformationOperation;
use App\Modules\Assets\Domain\Variant\TransformationSpec;
use App\Modules\Assets\Domain\Variant\TransformationStep;
use App\Modules\Assets\Infrastructure\Persistence\DatabaseAssetRepository;
use App\Modules\Assets\Infrastructure\Storage\WorkspaceAssetObjectStore;
use App\Modules\Core\Infrastructure\Storage\LaravelObjectStore;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) === false) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0033 final PostgreSQL certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0033 final persistence certification requires PostgreSQL.');
    }
});

function task0033CertificationWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $unique = Str::lower(Str::random(8));
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0033 '.$suffix,
        'slug' => 'task0033-cert-'.$suffix.'-'.$unique,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0033 Certification '.$suffix,
        'slug' => 'task0033-cert-workspace-'.$suffix.'-'.$unique,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

function task0033CertificationAsset(string $workspaceId, string $name, DateTimeImmutable $at): CanonicalAsset
{
    return new CanonicalAsset(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: $name,
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Draft,
        createdByActorId: 'task0033-certifier',
        auditProvenance: ['source' => 'task0033-final-certification'],
        createdAt: $at,
    );
}

function task0033CertificationObservation(string $contents, int $width = 1200, int $height = 800): IngestionObservation
{
    return new IngestionObservation(
        contentSha256: hash('sha256', $contents),
        observedMediaType: 'image/png',
        byteSize: strlen($contents),
        width: $width,
        height: $height,
    );
}

function task0033CertificationOriginal(
    CanonicalAsset $asset,
    string $contents,
    string $idempotencyKey,
    DateTimeImmutable $at,
): AssetOriginal {
    $id = (string) Str::uuid();

    return AssetOriginal::initialFor(
        asset: $asset,
        id: $id,
        observation: task0033CertificationObservation($contents),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$asset->workspaceId.'/assets/originals/'.$id.'.png',
        sourceMetadata: ['source' => 'certification-upload'],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'task0033-certifier',
        auditProvenance: ['source' => 'task0033-final-certification'],
        idempotencyKey: $idempotencyKey,
        createdAt: $at,
    );
}

function task0033CertificationVariantPlan(
    string $workspaceId,
    string $sourceOriginalId,
    string $idempotencyKey,
): VariantPlan {
    return new VariantPlan(
        workspaceId: $workspaceId,
        sourceOriginalId: $sourceOriginalId,
        transformation: new TransformationSpec([
            new TransformationStep(
                operation: TransformationOperation::Resize,
                parameters: ['width' => 640, 'fit' => 'contain'],
            ),
            new TransformationStep(
                operation: TransformationOperation::Quality,
                parameters: ['quality' => 84],
            ),
        ]),
        processor: new ProcessorIdentity('task0033-image-processor', '1.0.0'),
        idempotencyKey: $idempotencyKey,
    );
}

it('fails closed when an original idempotency key is replayed with different immutable evidence', function () {
    $workspaceId = task0033CertificationWorkspace('original-replay');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T08:00:00+00:00');
    $asset = task0033CertificationAsset($workspaceId, 'Replay protected asset', $at);
    $repository->createAsset($asset);

    $original = task0033CertificationOriginal($asset, 'trusted-original', 'stable-original-replay', $at);
    $repository->appendOriginal($original);

    $conflict = task0033CertificationOriginal(
        $asset,
        'different-binary',
        'stable-original-replay',
        new DateTimeImmutable('2026-09-19T08:00:01+00:00'),
    );

    expect(fn () => $repository->appendOriginal($conflict))
        ->toThrow(InvalidArgumentException::class, 'idempotency key conflicts')
        ->and(DB::table('asset_originals')->where('workspace_id', $workspaceId)->count())->toBe(1)
        ->and($repository->findOriginal($workspaceId, $original->id)?->observation->contentSha256)
        ->toBe(hash('sha256', 'trusted-original'));
});

it('rejects cross-asset and cross-workspace original lineage without mutating history', function () {
    $workspaceA = task0033CertificationWorkspace('lineage-a');
    $workspaceB = task0033CertificationWorkspace('lineage-b');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T09:00:00+00:00');

    $assetA = task0033CertificationAsset($workspaceA, 'Asset A', $at);
    $assetB = task0033CertificationAsset($workspaceA, 'Asset B', $at);
    $foreignAsset = task0033CertificationAsset($workspaceB, 'Foreign asset', $at);
    $repository->createAsset($assetA);
    $repository->createAsset($assetB);
    $repository->createAsset($foreignAsset);

    $parent = task0033CertificationOriginal($assetA, 'parent-a', 'parent-a', $at);
    $foreignParent = task0033CertificationOriginal($foreignAsset, 'foreign-parent', 'foreign-parent', $at);
    $repository->appendOriginal($parent);
    $repository->appendOriginal($foreignParent);

    $crossAsset = new AssetOriginal(
        id: (string) Str::uuid(),
        workspaceId: $workspaceA,
        assetId: $assetB->id,
        parentOriginalId: $parent->id,
        versionNumber: 2,
        schemaVersion: AssetOriginal::SCHEMA_VERSION,
        observation: task0033CertificationObservation('cross-asset'),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceA.'/assets/originals/cross-asset.png',
        sourceMetadata: [],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'task0033-certifier',
        auditProvenance: [],
        idempotencyKey: 'cross-asset',
        createdAt: new DateTimeImmutable('2026-09-19T09:01:00+00:00'),
    );

    $foreignLineage = new AssetOriginal(
        id: (string) Str::uuid(),
        workspaceId: $workspaceA,
        assetId: $assetA->id,
        parentOriginalId: $foreignParent->id,
        versionNumber: 2,
        schemaVersion: AssetOriginal::SCHEMA_VERSION,
        observation: task0033CertificationObservation('foreign-lineage'),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceA.'/assets/originals/foreign-lineage.png',
        sourceMetadata: [],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'task0033-certifier',
        auditProvenance: [],
        idempotencyKey: 'foreign-lineage',
        createdAt: new DateTimeImmutable('2026-09-19T09:01:00+00:00'),
    );

    expect(fn () => $repository->appendOriginal($crossAsset))
        ->toThrow(InvalidArgumentException::class, 'different canonical asset')
        ->and(fn () => $repository->appendOriginal($foreignLineage))
        ->toThrow(AuthorizationException::class, 'parent access denied')
        ->and(DB::table('asset_originals')->where('workspace_id', $workspaceA)->count())->toBe(1)
        ->and(DB::table('asset_originals')->where('workspace_id', $workspaceB)->count())->toBe(1);
});

it('rejects deterministic variant replay when persisted output evidence changes', function () {
    $workspaceId = task0033CertificationWorkspace('variant-conflict');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T10:00:00+00:00');
    $asset = task0033CertificationAsset($workspaceId, 'Variant asset', $at);
    $repository->createAsset($asset);

    $source = task0033CertificationOriginal($asset, 'variant-source', 'variant-source', $at);
    $repository->appendOriginal($source);

    $plan = task0033CertificationVariantPlan($workspaceId, $source->id, 'variant-request-a');
    $variant = $repository->appendVariant(
        id: (string) Str::uuid(),
        plan: $plan,
        output: task0033CertificationObservation('first-output', 640, 427),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceId.'/assets/variants/first.png',
        auditProvenance: ['source' => 'certification'],
        createdAt: $at,
    );

    $equivalentRequestWithDifferentOutput = task0033CertificationVariantPlan(
        $workspaceId,
        $source->id,
        'variant-request-b',
    );

    expect(fn () => $repository->appendVariant(
        id: (string) Str::uuid(),
        plan: $equivalentRequestWithDifferentOutput,
        output: task0033CertificationObservation('tampered-output', 640, 427),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceId.'/assets/variants/tampered.png',
        auditProvenance: ['source' => 'certification'],
        createdAt: $at,
    ))->toThrow(InvalidArgumentException::class, 'conflicts with different persisted output')
        ->and(DB::table('asset_variants')->where('workspace_id', $workspaceId)->count())->toBe(1)
        ->and($repository->findVariant($workspaceId, $variant->id)?->output->contentSha256)
        ->toBe(hash('sha256', 'first-output'));
});

it('proves database immutability and detects object-store tampering without deleting the source', function () {
    $workspaceId = task0033CertificationWorkspace('immutability');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T11:00:00+00:00');
    $asset = task0033CertificationAsset($workspaceId, 'Immutable asset', $at);
    $repository->createAsset($asset);

    $original = task0033CertificationOriginal($asset, 'immutable-source', 'immutable-source', $at);
    $repository->appendOriginal($original);
    $variant = $repository->appendVariant(
        id: (string) Str::uuid(),
        plan: task0033CertificationVariantPlan($workspaceId, $original->id, 'immutable-variant'),
        output: task0033CertificationObservation('immutable-variant-output', 640, 427),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceId.'/assets/variants/immutable.png',
        auditProvenance: [],
        createdAt: $at,
    );

    expect(fn () => DB::connection()->transaction(
        fn () => DB::table('asset_originals')->where('id', $original->id)->update(['byte_size' => 999]),
    ))->toThrow(QueryException::class)
        ->and(fn () => DB::connection()->transaction(
            fn () => DB::table('asset_variants')->where('id', $variant->id)->delete(),
        ))
        ->toThrow(QueryException::class);

    Storage::fake('local');
    $rawStore = new LaravelObjectStore(app(FilesystemManager::class), 'local');
    $safeStore = new WorkspaceAssetObjectStore($rawStore);
    $key = 'workspaces/'.$workspaceId.'/assets/originals/tamper.bin';
    $contents = 'verified-object';
    $hash = hash('sha256', $contents);

    $safeStore->putImmutable($workspaceId, $key, $contents, $hash);
    $rawStore->put($key, 'tampered-object');

    expect(fn () => $safeStore->getVerified($workspaceId, $key, $hash))
        ->toThrow(RuntimeException::class, 'hash verification failed')
        ->and($repository->findOriginal($workspaceId, $original->id)?->id)->toBe($original->id);
});
