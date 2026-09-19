<?php

use App\Modules\Assets\Domain\Asset\AssetKind;
use App\Modules\Assets\Domain\Asset\AssetLifecycle;
use App\Modules\Assets\Domain\Asset\CanonicalAsset;
use App\Modules\Assets\Domain\Variant\ProcessorIdentity;
use App\Modules\Assets\Domain\Variant\TransformationOperation;
use App\Modules\Assets\Domain\Variant\TransformationSpec;
use App\Modules\Assets\Domain\Variant\TransformationStep;
use App\Modules\Assets\Infrastructure\Storage\WorkspaceAssetObjectStore;
use App\Modules\Core\Infrastructure\Storage\LaravelObjectStore;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

it('rejects sensitive provenance and rights metadata recursively', function () {
    $workspaceId = (string) Str::uuid();

    expect(fn () => new CanonicalAsset(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Sensitive metadata asset',
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Draft,
        createdByActorId: 'security-certifier',
        auditProvenance: [
            'ingestion' => [
                'authorization_token' => 'must-never-be-canonical',
            ],
        ],
        createdAt: new DateTimeImmutable('2026-09-19T12:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive asset metadata key is forbidden');
});

it('keeps transform specifications typed, bounded and free of remote fetch or shell parameters', function () {
    expect(fn () => new TransformationStep(
        operation: TransformationOperation::Resize,
        parameters: [
            'width' => 800,
            'url' => 'https://attacker.example/payload',
        ],
    ))->toThrow(InvalidArgumentException::class, 'Unsupported resize transformation parameter')
        ->and(fn () => new TransformationStep(
            operation: TransformationOperation::Convert,
            parameters: [
                'media_type' => 'image/png',
                'command' => 'rm -rf /',
            ],
        ))->toThrow(InvalidArgumentException::class, 'Unsupported convert transformation parameter')
        ->and(fn () => new ProcessorIdentity('image-worker;curl', '1.0.0'))
        ->toThrow(InvalidArgumentException::class, 'unsupported characters')
        ->and(fn () => new TransformationSpec(array_fill(
            0,
            17,
            new TransformationStep(
                operation: TransformationOperation::Quality,
                parameters: ['quality' => 80],
            ),
        )))->toThrow(InvalidArgumentException::class, 'maximum step count');
});

it('fails closed for traversal, foreign workspace keys and mismatched object hashes', function () {
    Storage::fake('local');

    $workspaceId = (string) Str::uuid();
    $foreignWorkspaceId = (string) Str::uuid();
    $rawStore = new LaravelObjectStore(app(FilesystemManager::class), 'local');
    $store = new WorkspaceAssetObjectStore($rawStore);
    $contents = 'security-certified-object';
    $hash = hash('sha256', $contents);
    $key = 'workspaces/'.$workspaceId.'/assets/originals/security.bin';

    expect(fn () => $store->putImmutable(
        $workspaceId,
        '../escape.bin',
        'x',
        hash('sha256', 'x'),
    ))->toThrow(InvalidArgumentException::class, 'unsafe path')
        ->and(fn () => $store->putImmutable(
            $workspaceId,
            '/workspaces/'.$workspaceId.'/assets/originals/rooted.bin',
            'x',
            hash('sha256', 'x'),
        ))->toThrow(InvalidArgumentException::class, 'unsafe path')
        ->and(fn () => $store->putImmutable(
            $workspaceId,
            'workspaces/'.$workspaceId.'/assets/../escape.bin',
            'x',
            hash('sha256', 'x'),
        ))->toThrow(InvalidArgumentException::class, 'unsafe path')
        ->and(fn () => $store->putImmutable(
            $workspaceId,
            'workspaces/'.$foreignWorkspaceId.'/assets/originals/foreign.bin',
            'x',
            hash('sha256', 'x'),
        ))->toThrow(AuthorizationException::class, 'outside the requested workspace boundary')
        ->and(fn () => $store->putImmutable(
            $workspaceId,
            $key,
            $contents,
            hash('sha256', 'different'),
        ))->toThrow(InvalidArgumentException::class, 'does not match the expected SHA-256');
});

it('does not expose delete semantics through the canonical immutable asset storage boundary', function () {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(WorkspaceAssetObjectStore::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toContain('__construct', 'putImmutable', 'getVerified', 'exists')
        ->not->toContain('delete')
        ->not->toContain('move')
        ->not->toContain('copyFromUrl')
        ->not->toContain('fetchRemote');
});
