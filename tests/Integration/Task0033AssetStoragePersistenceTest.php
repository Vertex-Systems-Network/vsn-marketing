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
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0033 PostgreSQL asset persistence tests.');
    }
});

function task0033StorageWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $unique = Str::lower(Str::random(8));
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0033 '.$suffix,
        'slug' => 'task0033-'.$suffix.'-'.$unique,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0033 Workspace '.$suffix,
        'slug' => 'task0033-workspace-'.$suffix.'-'.$unique,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

function task0033StorageAsset(string $workspaceId, string $id, DateTimeImmutable $at): CanonicalAsset
{
    return new CanonicalAsset(
        id: $id,
        workspaceId: $workspaceId,
        name: 'Canonical asset',
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Draft,
        createdByActorId: 'task0033-user',
        auditProvenance: ['source' => 'integration'],
        createdAt: $at,
    );
}

function task0033StorageObservation(string $contents, int $width = 1200, int $height = 800): IngestionObservation
{
    return new IngestionObservation(
        contentSha256: hash('sha256', $contents),
        observedMediaType: 'image/png',
        byteSize: strlen($contents),
        width: $width,
        height: $height,
    );
}

function task0033StorageOriginal(
    CanonicalAsset $asset,
    string $id,
    string $contents,
    string $idempotencyKey,
    DateTimeImmutable $at,
): AssetOriginal {
    return AssetOriginal::initialFor(
        asset: $asset,
        id: $id,
        observation: task0033StorageObservation($contents),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$asset->workspaceId.'/assets/originals/'.$id.'.png',
        sourceMetadata: ['source' => 'upload'],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'task0033-user',
        auditProvenance: ['source' => 'integration'],
        idempotencyKey: $idempotencyKey,
        createdAt: $at,
    );
}

function task0033VariantPlan(string $workspaceId, string $sourceOriginalId, string $idempotencyKey): VariantPlan
{
    return new VariantPlan(
        workspaceId: $workspaceId,
        sourceOriginalId: $sourceOriginalId,
        transformation: new TransformationSpec([
            new TransformationStep(
                operation: TransformationOperation::Resize,
                parameters: ['width' => 600, 'fit' => 'contain'],
            ),
            new TransformationStep(
                operation: TransformationOperation::Quality,
                parameters: ['quality' => 82],
            ),
        ]),
        processor: new ProcessorIdentity('image-worker', '1.0.0'),
        idempotencyKey: $idempotencyKey,
    );
}

it('persists immutable asset originals with replay-safe lineage on PostgreSQL', function () {
    $workspaceId = task0033StorageWorkspace('originals');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T05:00:00+00:00');
    $asset = task0033StorageAsset($workspaceId, (string) Str::uuid(), $at);
    $original = task0033StorageOriginal($asset, (string) Str::uuid(), 'original-v1', 'original-v1', $at);

    expect($repository->createAsset($asset)->id)->toBe($asset->id)
        ->and($repository->createAsset($asset)->id)->toBe($asset->id);

    $first = $repository->appendOriginal($original);
    $replay = $repository->appendOriginal($original);

    expect($replay->id)->toBe($first->id)
        ->and($repository->findOriginal($workspaceId, $first->id)?->observation->contentSha256)
        ->toBe($first->observation->contentSha256)
        ->and(DB::table('asset_originals')->where('workspace_id', $workspaceId)->count())->toBe(1);

    $replacementAt = new DateTimeImmutable('2026-09-19T05:01:00+00:00');
    $replacement = $first->replaceWith(
        id: (string) Str::uuid(),
        observation: task0033StorageObservation('original-v2'),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceId.'/assets/originals/replacement.png',
        sourceMetadata: ['source' => 'replacement'],
        rightsMetadata: ['license' => 'owned'],
        createdByActorId: 'task0033-user',
        auditProvenance: ['source' => 'integration'],
        idempotencyKey: 'original-v2',
        createdAt: $replacementAt,
    );
    $repository->appendOriginal($replacement);

    expect($repository->findOriginal($workspaceId, $replacement->id)?->parentOriginalId)->toBe($first->id)
        ->and($repository->findOriginal($workspaceId, $replacement->id)?->versionNumber)->toBe(2)
        ->and(fn () => DB::table('asset_originals')->where('id', $first->id)->update(['byte_size' => 999]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('asset_originals')->where('id', $first->id)->delete())
        ->toThrow(QueryException::class);
});

it('deduplicates deterministic variant requests and persists exact output lineage', function () {
    $workspaceId = task0033StorageWorkspace('variants');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T06:00:00+00:00');
    $asset = task0033StorageAsset($workspaceId, (string) Str::uuid(), $at);
    $repository->createAsset($asset);
    $original = task0033StorageOriginal($asset, (string) Str::uuid(), 'variant-source', 'source-v1', $at);
    $repository->appendOriginal($original);

    $output = task0033StorageObservation('variant-output', 600, 400);
    $storageKey = 'workspaces/'.$workspaceId.'/assets/variants/resize-600.png';

    $first = $repository->appendVariant(
        id: (string) Str::uuid(),
        plan: task0033VariantPlan($workspaceId, $original->id, 'variant-replay-a'),
        output: $output,
        storageDisk: 's3',
        storageKey: $storageKey,
        auditProvenance: ['source' => 'integration'],
        createdAt: $at,
    );
    $sameRequest = $repository->appendVariant(
        id: (string) Str::uuid(),
        plan: task0033VariantPlan($workspaceId, $original->id, 'variant-replay-b'),
        output: $output,
        storageDisk: 's3',
        storageKey: $storageKey,
        auditProvenance: ['source' => 'integration'],
        createdAt: $at,
    );

    expect($sameRequest->id)->toBe($first->id)
        ->and($repository->findVariant($workspaceId, $first->id)?->sourceOriginalId)->toBe($original->id)
        ->and($repository->findVariant($workspaceId, $first->id)?->transformationHash)
        ->toBe(task0033VariantPlan($workspaceId, $original->id, 'ignored')->transformation->hash())
        ->and(DB::table('asset_variants')->where('workspace_id', $workspaceId)->count())->toBe(1)
        ->and(fn () => DB::table('asset_variants')->where('id', $first->id)->delete())
        ->toThrow(QueryException::class);
});

it('fails closed on foreign-workspace asset, original and variant access', function () {
    $workspaceA = task0033StorageWorkspace('scope-a');
    $workspaceB = task0033StorageWorkspace('scope-b');
    $repository = app(DatabaseAssetRepository::class);
    $at = new DateTimeImmutable('2026-09-19T07:00:00+00:00');
    $asset = task0033StorageAsset($workspaceA, (string) Str::uuid(), $at);
    $repository->createAsset($asset);
    $original = task0033StorageOriginal($asset, (string) Str::uuid(), 'scope-source', 'scope-source', $at);
    $repository->appendOriginal($original);

    $variant = $repository->appendVariant(
        id: (string) Str::uuid(),
        plan: task0033VariantPlan($workspaceA, $original->id, 'scope-variant'),
        output: task0033StorageObservation('scope-variant-output', 600, 400),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceA.'/assets/variants/scope.png',
        auditProvenance: [],
        createdAt: $at,
    );

    expect(fn () => $repository->findAsset($workspaceB, $asset->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $repository->findOriginal($workspaceB, $original->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $repository->findVariant($workspaceB, $variant->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $repository->appendVariant(
            id: (string) Str::uuid(),
            plan: task0033VariantPlan($workspaceB, $original->id, 'foreign-source'),
            output: task0033StorageObservation('foreign-output', 600, 400),
            storageDisk: 's3',
            storageKey: 'workspaces/'.$workspaceB.'/assets/variants/foreign.png',
            auditProvenance: [],
            createdAt: $at,
        ))->toThrow(AuthorizationException::class);
});

it('enforces immutable workspace-prefixed object storage with hash verification', function () {
    Storage::fake('local');

    $workspaceId = (string) Str::uuid();
    $foreignWorkspace = (string) Str::uuid();
    $objects = new LaravelObjectStore(app(FilesystemManager::class), 'local');
    $storage = new WorkspaceAssetObjectStore($objects);
    $contents = 'immutable-object-body';
    $hash = hash('sha256', $contents);
    $key = 'workspaces/'.$workspaceId.'/assets/originals/object.bin';

    $storage->putImmutable($workspaceId, $key, $contents, $hash);
    $storage->putImmutable($workspaceId, $key, $contents, $hash);

    expect($storage->exists($workspaceId, $key))->toBeTrue()
        ->and($storage->getVerified($workspaceId, $key, $hash))->toBe($contents)
        ->and(fn () => $storage->putImmutable($workspaceId, $key, 'different', hash('sha256', 'different')))
        ->toThrow(RuntimeException::class, 'different content')
        ->and(fn () => $storage->exists($foreignWorkspace, $key))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $storage->putImmutable($workspaceId, '../escape.bin', 'x', hash('sha256', 'x')))
        ->toThrow(InvalidArgumentException::class);
});
