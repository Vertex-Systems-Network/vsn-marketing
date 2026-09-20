<?php

use App\Modules\Content\Application\Brand\BrandReferenceResolver;
use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Domain\Binding\ResolvedBindings;
use App\Modules\Content\Domain\Brand\BrandKit;
use App\Modules\Content\Domain\Brand\BrandReference;
use App\Modules\Content\Domain\Brand\BrandStyleToken;
use App\Modules\Content\Domain\Brand\BrandTokenKind;
use App\Modules\Content\Domain\Brand\BrandVersion;
use App\Modules\Content\Domain\Brand\BrandVersionStatus;
use DateTimeImmutable;
use InvalidArgumentException;

function task0035BrandKit(string $id = 'brand-kit-1', string $workspaceId = 'workspace-1'): BrandKit
{
    return new BrandKit(
        id: $id,
        workspaceId: $workspaceId,
        name: 'VSN Brand '.$id,
        createdByActorId: 'brand-admin',
        createdAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
    );
}

/**
 * @param  list<BrandStyleToken>|null  $styleTokens
 * @param  list<string>  $assetReferences
 * @param  array<string, mixed>  $identityMetadata
 * @param  array<string, mixed>  $defaults
 * @param  array<string, mixed>  $auditProvenance
 */
function task0035BrandVersion(
    string $id = 'brand-v1',
    string $workspaceId = 'workspace-1',
    string $brandKitId = 'brand-kit-1',
    ?array $styleTokens = null,
    array $assetReferences = ['asset-logo-v1'],
    array $identityMetadata = ['display_name' => 'VSN'],
    array $defaults = ['locale' => 'en', 'logo_asset' => 'asset-logo-v1'],
    array $auditProvenance = ['source' => 'task0035-brand-kit-test'],
    BrandVersionStatus $status = BrandVersionStatus::Published,
): BrandVersion {
    $kit = task0035BrandKit($brandKitId, $workspaceId);

    return BrandVersion::initialFor(
        brandKit: $kit,
        id: $id,
        styleTokens: $styleTokens ?? [
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039'),
        ],
        identityMetadata: $identityMetadata,
        assetReferences: $assetReferences,
        defaults: $defaults,
        createdByActorId: 'brand-admin',
        auditProvenance: $auditProvenance,
        idempotencyKey: 'idem-'.$id,
        createdAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        status: $status,
    );
}

it('canonicalizes exact brand versions tokens assets and metadata deterministically', function () {
    $hasher = new CanonicalJsonHasher;
    $left = task0035BrandVersion(
        styleTokens: [
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039'),
        ],
        assetReferences: ['asset-logo-v2', 'asset-logo-v1'],
        identityMetadata: ['legal_name' => 'VSN Marketing', 'display_name' => 'VSN'],
        defaults: ['logo_asset' => 'asset-logo-v1', 'locale' => 'en'],
    );
    $right = task0035BrandVersion(
        styleTokens: [
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039'),
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
        ],
        assetReferences: ['asset-logo-v1', 'asset-logo-v2'],
        identityMetadata: ['display_name' => 'VSN', 'legal_name' => 'VSN Marketing'],
        defaults: ['locale' => 'en', 'logo_asset' => 'asset-logo-v1'],
    );

    expect($hasher->hash($left->toArray()))->toBe($hasher->hash($right->toArray()))
        ->and($left->toArray()['style_tokens'])->toBe([
            ['key' => 'color.primary', 'kind' => 'color', 'value' => '#006039'],
            ['key' => 'spacing.base', 'kind' => 'spacing', 'value' => '16px'],
        ])
        ->and($left->toArray()['asset_references'])->toBe(['asset-logo-v1', 'asset-logo-v2']);
});

it('forks brand versions without mutating the published source version', function () {
    $published = task0035BrandVersion();

    $draft = $published->fork(
        id: 'brand-v2',
        styleTokens: [
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#111111'),
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
        ],
        identityMetadata: ['display_name' => 'VSN'],
        assetReferences: ['asset-logo-v2'],
        defaults: ['locale' => 'en', 'logo_asset' => 'asset-logo-v2'],
        createdByActorId: 'brand-editor',
        auditProvenance: ['source' => 'brand-refresh'],
        idempotencyKey: 'idem-brand-v2',
        createdAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
    );

    expect($published->id)->toBe('brand-v1')
        ->and($published->versionNumber)->toBe(1)
        ->and($published->parentVersionId)->toBeNull()
        ->and($published->status)->toBe(BrandVersionStatus::Published)
        ->and($published->styleTokenMap()['color.primary'])->toBe('#006039')
        ->and($draft->id)->toBe('brand-v2')
        ->and($draft->parentVersionId)->toBe('brand-v1')
        ->and($draft->versionNumber)->toBe(2)
        ->and($draft->status)->toBe(BrandVersionStatus::Draft)
        ->and($draft->styleTokenMap()['color.primary'])->toBe('#111111');

    expect(fn () => $published->fork(
        id: 'brand-invalid',
        styleTokens: $published->styleTokens,
        identityMetadata: $published->identityMetadata,
        assetReferences: $published->assetReferences,
        defaults: $published->defaults,
        createdByActorId: 'brand-editor',
        auditProvenance: [],
        idempotencyKey: 'idem-brand-invalid',
        createdAt: new DateTimeImmutable('2026-09-20T11:59:59+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot precede its parent');
});

it('resolves exact brand references deterministically and fails closed across workspaces', function () {
    $resolver = new BrandReferenceResolver;
    $brandA = task0035BrandVersion(id: 'brand-a-v1', brandKitId: 'brand-a');
    $brandB = task0035BrandVersion(id: 'brand-b-v1', brandKitId: 'brand-b');

    $first = $resolver->resolve(
        'workspace-1',
        [
            new BrandReference('workspace-1', 'brand-b', 'brand-b-v1'),
            new BrandReference('workspace-1', 'brand-a', 'brand-a-v1'),
        ],
        [$brandB, $brandA],
    );
    $reordered = $resolver->resolve(
        'workspace-1',
        [
            new BrandReference('workspace-1', 'brand-a', 'brand-a-v1'),
            new BrandReference('workspace-1', 'brand-b', 'brand-b-v1'),
        ],
        [$brandA, $brandB],
    );

    expect($first->versionIds())->toBe(['brand-a-v1', 'brand-b-v1'])
        ->and($first->identity(new CanonicalJsonHasher))
        ->toBe($reordered->identity(new CanonicalJsonHasher))
        ->and($first->tokenMap())->toBe([
            'brand-a' => ['color.primary' => '#006039', 'spacing.base' => '16px'],
            'brand-b' => ['color.primary' => '#006039', 'spacing.base' => '16px'],
        ]);

    $foreign = task0035BrandVersion(
        id: 'brand-a-v1',
        workspaceId: 'workspace-2',
        brandKitId: 'brand-a',
    );

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-a', 'brand-a-v1')],
        [$foreign],
    ))->toThrow(InvalidArgumentException::class, 'belongs to another workspace');

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-2', 'brand-a', 'brand-a-v1')],
        [$brandA],
    ))->toThrow(InvalidArgumentException::class, 'cannot cross workspaces');

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-a', 'missing-v1')],
        [$brandA],
    ))->toThrow(InvalidArgumentException::class, 'Exact brand version is unavailable');
});

it('rejects duplicate ambiguous and mismatched exact brand references', function () {
    $resolver = new BrandReferenceResolver;
    $v1 = task0035BrandVersion(id: 'brand-v1');
    $v2 = $v1->fork(
        id: 'brand-v2',
        styleTokens: $v1->styleTokens,
        identityMetadata: $v1->identityMetadata,
        assetReferences: $v1->assetReferences,
        defaults: $v1->defaults,
        createdByActorId: 'brand-editor',
        auditProvenance: [],
        idempotencyKey: 'idem-brand-v2',
        createdAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
    );

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [
            new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1'),
            new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1'),
        ],
        [$v1],
    ))->toThrow(InvalidArgumentException::class, 'Duplicate exact brand reference');

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [
            new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1'),
            new BrandReference('workspace-1', 'brand-kit-1', 'brand-v2'),
        ],
        [$v1, $v2],
    ))->toThrow(InvalidArgumentException::class, 'Only one exact brand version');

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'another-kit', 'brand-v1')],
        [$v1],
    ))->toThrow(InvalidArgumentException::class, 'does not belong to the referenced brand kit');

    expect(fn () => $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1')],
        [$v1, $v1],
    ))->toThrow(InvalidArgumentException::class, 'Duplicate brand version catalog id');
});

it('rejects sensitive provider-owned and non-json brand metadata', function () {
    expect(fn () => task0035BrandVersion(
        identityMetadata: ['nested' => ['authorization_token' => 'secret']],
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or provider-owned brand metadata key is forbidden');

    expect(fn () => task0035BrandVersion(
        defaults: ['provider_template_id' => 'remote-template-1'],
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or provider-owned brand metadata key is forbidden');

    expect(fn () => task0035BrandVersion(
        auditProvenance: ['provider' => ['upload_id' => 'remote-upload-1']],
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or provider-owned brand metadata key is forbidden');

    expect(fn () => task0035BrandVersion(
        identityMetadata: ['unsafe' => new stdClass],
    ))->toThrow(InvalidArgumentException::class, 'must be JSON-compatible');

    expect(fn () => task0035BrandVersion(
        assetReferences: ['asset-logo-v1', 'asset-logo-v1'],
    ))->toThrow(InvalidArgumentException::class, 'Duplicate brand asset reference');

    expect(fn () => task0035BrandVersion(
        styleTokens: [
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039'),
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#111111'),
        ],
    ))->toThrow(InvalidArgumentException::class, 'Duplicate brand style token key');
});

it('validates typed style tokens without permitting executable objects', function () {
    expect(fn () => new BrandStyleToken('number.scale', BrandTokenKind::Number, '1.5'))
        ->toThrow(InvalidArgumentException::class, 'must be numeric');

    expect(fn () => new BrandStyleToken('feature.flag', BrandTokenKind::Boolean, 'true'))
        ->toThrow(InvalidArgumentException::class, 'must be boolean');

    expect(fn () => new BrandStyleToken('bad key', BrandTokenKind::Text, 'value'))
        ->toThrow(InvalidArgumentException::class, 'bounded stable identifier');

    expect((new BrandStyleToken('number.scale', BrandTokenKind::Number, 1.5))->toArray())
        ->toBe(['key' => 'number.scale', 'kind' => 'number', 'value' => 1.5])
        ->and((new BrandStyleToken('feature.flag', BrandTokenKind::Boolean, true))->toArray())
        ->toBe(['key' => 'feature.flag', 'kind' => 'boolean', 'value' => true]);
});

it('pins exact brand version references into deterministic render input identity', function () {
    $resolver = new BrandReferenceResolver;
    $v1 = task0035BrandVersion(id: 'brand-v1');
    $v2 = $v1->fork(
        id: 'brand-v2',
        styleTokens: [
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#111111'),
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
        ],
        identityMetadata: $v1->identityMetadata,
        assetReferences: $v1->assetReferences,
        defaults: $v1->defaults,
        createdByActorId: 'brand-editor',
        auditProvenance: [],
        idempotencyKey: 'idem-brand-v2',
        createdAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
        status: BrandVersionStatus::Published,
    );

    $resolvedV1 = $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1')],
        [$v1],
    );
    $resolvedV2 = $resolver->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v2')],
        [$v2],
    );

    $bindings = new ResolvedBindings(
        variables: ['name' => 'Ada'],
        localized: ['subject' => 'Hello'],
        requestedLocale: 'en',
        localeChain: ['en'],
    );

    $first = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        dependencyVersionIds: ['component-v1'],
        bindings: $bindings,
        assetReferences: ['asset-logo-v1'],
        brandReferences: $resolvedV1->versionIds(),
    );
    $replayed = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        dependencyVersionIds: ['component-v1'],
        bindings: $bindings,
        assetReferences: ['asset-logo-v1'],
        brandReferences: ['brand-v1'],
    );
    $changedBrand = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        dependencyVersionIds: ['component-v1'],
        bindings: $bindings,
        assetReferences: ['asset-logo-v1'],
        brandReferences: $resolvedV2->versionIds(),
    );

    $hasher = new CanonicalJsonHasher;

    expect($first->identity($hasher))->toBe($replayed->identity($hasher))
        ->and($changedBrand->identity($hasher))->not->toBe($first->identity($hasher));
});
