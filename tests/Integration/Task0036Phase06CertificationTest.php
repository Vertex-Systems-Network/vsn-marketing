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
use App\Modules\Content\Application\Brand\BrandReferenceResolver;
use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Application\DependencyResolution\DependencyResolver;
use App\Modules\Content\Application\Preview\PreviewFinding;
use App\Modules\Content\Application\Preview\PreviewIsolationPolicy;
use App\Modules\Content\Application\Preview\PreviewPlanner;
use App\Modules\Content\Application\Preview\PreviewRegressionResult;
use App\Modules\Content\Application\Preview\PreviewViewport;
use App\Modules\Content\Application\Rendering\RenderCompilerPlanner;
use App\Modules\Content\Domain\Binding\ResolvedBindings;
use App\Modules\Content\Domain\Brand\BrandKit;
use App\Modules\Content\Domain\Brand\BrandReference;
use App\Modules\Content\Domain\Brand\BrandStyleToken;
use App\Modules\Content\Domain\Brand\BrandTokenKind;
use App\Modules\Content\Domain\Brand\BrandVersion;
use App\Modules\Content\Domain\Brand\BrandVersionStatus;
use App\Modules\Content\Domain\Document\CanonicalNodeType;
use App\Modules\Content\Domain\Document\ContentDocument;
use App\Modules\Content\Domain\Document\ContentLifecycle;
use App\Modules\Content\Domain\Document\ContentNode;
use App\Modules\Content\Domain\Document\ContentTree;
use App\Modules\Content\Domain\Render\RenderCompilationPlan;
use App\Modules\Content\Domain\Render\RendererExecutionPolicy;
use App\Modules\Content\Domain\Render\RendererIdentity;
use App\Modules\Content\Domain\Render\RenderTarget;
use App\Modules\Content\Domain\Version\ContentVersion;
use App\Modules\Content\Domain\Version\ContentVersionStatus;
use App\Modules\Providers\Application\TemplateSync\ProviderTemplateSyncPlanner;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\Templates\ProviderTemplateDrift;
use App\Modules\Providers\Domain\Templates\ProviderTemplateMapping;
use App\Modules\Providers\Domain\Templates\ProviderTemplateObservation;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncAction;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncRequest;
use App\Modules\Providers\Infrastructure\TemplateSync\InMemoryProviderTemplateSyncLedger;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use DateTimeImmutable;
use InvalidArgumentException;

function task0036ContentTree(string $text): ContentTree
{
    return new ContentTree(new ContentNode(
        nodeId: 'root',
        type: CanonicalNodeType::Root,
        children: [
            new ContentNode(
                nodeId: 'text',
                type: CanonicalNodeType::Text,
                properties: ['text' => $text],
            ),
        ],
    ));
}

function task0036ContentVersion(string $workspaceId = 'workspace-1'): ContentVersion
{
    $at = new DateTimeImmutable('2026-09-21T00:00:00+00:00');
    $document = new ContentDocument(
        id: 'content-1',
        workspaceId: $workspaceId,
        name: 'PHASE-06 canonical content',
        lifecycle: ContentLifecycle::Active,
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'task0036-certification'],
        createdAt: $at,
    );

    return ContentVersion::initialFor(
        document: $document,
        id: 'content-v1',
        tree: task0036ContentTree('Hello Ada'),
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'task0036-certification'],
        idempotencyKey: 'content-v1',
        createdAt: $at,
        status: ContentVersionStatus::Published,
    );
}

/** @param list<DependencyReference> $dependencies */
function task0036Definition(
    string $id,
    DefinitionKind $kind,
    string $workspaceId = 'workspace-1',
    array $dependencies = [],
): VersionedDefinition {
    return new VersionedDefinition(
        id: $id,
        workspaceId: $workspaceId,
        kind: $kind,
        ownerId: $kind === DefinitionKind::Template ? 'template-1' : 'component-1',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: $kind === DefinitionKind::Template ? VersionStatus::ExecutionPinned : VersionStatus::Published,
        dependencies: $dependencies,
        idempotencyKey: 'idem-'.$id,
        createdByActorId: 'phase-certifier',
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );
}

function task0036BrandVersion(string $workspaceId = 'workspace-1'): BrandVersion
{
    $kit = new BrandKit(
        id: 'brand-kit-1',
        workspaceId: $workspaceId,
        name: 'VSN Brand',
        createdByActorId: 'phase-certifier',
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );

    return BrandVersion::initialFor(
        brandKit: $kit,
        id: 'brand-v1',
        styleTokens: [
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039'),
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
        ],
        identityMetadata: ['display_name' => 'VSN'],
        assetReferences: ['asset-original-v1'],
        defaults: ['locale' => 'en'],
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'task0036-certification'],
        idempotencyKey: 'brand-v1',
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
        status: BrandVersionStatus::Published,
    );
}

function task0036AssetOriginal(string $workspaceId = 'workspace-1'): AssetOriginal
{
    $asset = new CanonicalAsset(
        id: 'asset-1',
        workspaceId: $workspaceId,
        name: 'PHASE-06 hero image',
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Active,
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'task0036-certification'],
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );
    $contents = 'task0036-original-binary';

    return AssetOriginal::initialFor(
        asset: $asset,
        id: 'asset-original-v1',
        observation: new IngestionObservation(
            contentSha256: hash('sha256', $contents),
            observedMediaType: 'image/png',
            byteSize: strlen($contents),
            width: 1200,
            height: 800,
        ),
        storageDisk: 's3',
        storageKey: 'workspaces/'.$workspaceId.'/assets/originals/asset-original-v1.png',
        sourceMetadata: ['source' => 'owned-upload'],
        rightsMetadata: ['license' => 'owned', 'usage' => 'marketing'],
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'task0036-certification'],
        idempotencyKey: 'asset-original-v1',
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );
}

function task0036VariantPlan(string $idempotencyKey): VariantPlan
{
    return new VariantPlan(
        workspaceId: 'workspace-1',
        sourceOriginalId: 'asset-original-v1',
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
        processor: new ProcessorIdentity('vsn-image-processor', '1.0.0'),
        idempotencyKey: $idempotencyKey,
    );
}

function task0036RenderPlan(RenderInputSnapshot $input): RenderCompilationPlan
{
    return (new RenderCompilerPlanner(new CanonicalJsonHasher))->plan(
        input: $input,
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('vsn-email-compiler', '1.2.3'),
        executionPolicy: new RendererExecutionPolicy(
            maxDurationMs: 3000,
            maxMemoryMb: 128,
            maxOutputBytes: 1000000,
            maxInputNodes: 3000,
        ),
        configuration: ['doctype' => 'html5', 'minify' => true],
    );
}

function task0036ProviderCapability(string $sourceVersion = '2026-09'): ProviderCapability
{
    return new ProviderCapability(
        id: 'capability-1',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        connectionId: 'connection-1',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'media_kinds' => ['image', 'text']],
        sourceUrl: 'https://example.test/provider-docs',
        sourceVersion: $sourceVersion,
        observedAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-21T00:00:00+00:00'),
    );
}

function task0036ProviderRequest(
    string $idempotencyKey = 'sync-1',
    string $canonicalVersionId = 'template-v1',
): ProviderTemplateSyncRequest {
    return new ProviderTemplateSyncRequest(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        canonicalTemplateVersionId: $canonicalVersionId,
        canonicalSourceIdentity: $canonicalVersionId === 'template-v1' ? str_repeat('a', 64) : str_repeat('d', 64),
        componentVersionIds: ['component-v1'],
        brandVersionIds: ['brand-v1'],
        templateKind: 'email',
        mediaKinds: ['image', 'text'],
        idempotencyKey: $idempotencyKey,
    );
}

function task0036ProviderMapping(): ProviderTemplateMapping
{
    return new ProviderTemplateMapping(
        id: 'mapping-1',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        providerTemplateReference: 'provider-template-42',
        lastSyncedCanonicalVersionId: null,
        lastSyncedDerivativeIdentity: null,
        lastObservedProviderFingerprint: null,
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );
}

function task0036ProviderObservation(string $fingerprint = ''): ProviderTemplateObservation
{
    return new ProviderTemplateObservation(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        providerTemplateReference: 'provider-template-42',
        providerFingerprint: $fingerprint !== '' ? $fingerprint : str_repeat('b', 64),
        sourceVersion: 'provider-template-api-v3',
        observedAt: new DateTimeImmutable('2026-09-21T00:05:00+00:00'),
    );
}

it('certifies exact pinned content dependency brand asset and render identities replay deterministically', function () {
    $hasher = new CanonicalJsonHasher;
    $contentV1 = task0036ContentVersion();
    $contentV2 = $contentV1->fork(
        id: 'content-v2',
        tree: task0036ContentTree('Hello Grace'),
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'revision'],
        idempotencyKey: 'content-v2',
        createdAt: new DateTimeImmutable('2026-09-21T00:10:00+00:00'),
        status: ContentVersionStatus::Published,
    );

    $component = task0036Definition('component-v1', DefinitionKind::Component);
    $template = task0036Definition(
        'template-v1',
        DefinitionKind::Template,
        dependencies: [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );
    $dependencies = (new DependencyResolver)->resolve($template, [$component]);

    $brand = task0036BrandVersion();
    $resolvedBrand = (new BrandReferenceResolver)->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1')],
        [$brand],
    );
    $asset = task0036AssetOriginal();

    $bindings = new ResolvedBindings(
        variables: ['first_name' => 'Ada'],
        localized: ['subject' => 'Hello'],
        requestedLocale: 'en',
        localeChain: ['en'],
    );

    $first = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: $contentV1->id,
        templateVersionId: $template->id,
        dependencyVersionIds: $dependencies->versionIds(),
        bindings: $bindings,
        assetReferences: [$asset->id],
        brandReferences: $resolvedBrand->versionIds(),
    );
    $replay = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        dependencyVersionIds: ['component-v1'],
        bindings: $bindings,
        assetReferences: ['asset-original-v1'],
        brandReferences: ['brand-v1'],
    );
    $changedContent = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: $contentV2->id,
        templateVersionId: $template->id,
        dependencyVersionIds: $dependencies->versionIds(),
        bindings: $bindings,
        assetReferences: [$asset->id],
        brandReferences: $resolvedBrand->versionIds(),
    );

    expect($hasher->hash($contentV1->tree->toArray()))->not->toBe($hasher->hash($contentV2->tree->toArray()))
        ->and($contentV1->tree->root->children[0]->properties['text'])->toBe('Hello Ada')
        ->and($contentV2->parentVersionId)->toBe('content-v1')
        ->and($first->identity($hasher))->toBe($replay->identity($hasher))
        ->and(task0036RenderPlan($first)->artifactIdentity)->toBe(task0036RenderPlan($replay)->artifactIdentity)
        ->and($changedContent->identity($hasher))->not->toBe($first->identity($hasher));
});

it('certifies immutable asset originals rights evidence and deterministic transform lineage', function () {
    $original = task0036AssetOriginal();
    $replacementContents = 'task0036-replacement-binary';
    $replacement = $original->replaceWith(
        id: 'asset-original-v2',
        observation: new IngestionObservation(
            contentSha256: hash('sha256', $replacementContents),
            observedMediaType: 'image/png',
            byteSize: strlen($replacementContents),
            width: 1200,
            height: 800,
        ),
        storageDisk: 's3',
        storageKey: 'workspaces/workspace-1/assets/originals/asset-original-v2.png',
        sourceMetadata: ['source' => 'owned-upload'],
        rightsMetadata: ['license' => 'owned', 'usage' => 'marketing'],
        createdByActorId: 'phase-certifier',
        auditProvenance: ['source' => 'replacement'],
        idempotencyKey: 'asset-original-v2',
        createdAt: new DateTimeImmutable('2026-09-21T00:10:00+00:00'),
    );

    $firstPlan = task0036VariantPlan('variant-request-a');
    $retryPlan = task0036VariantPlan('variant-request-b');

    expect($original->parentOriginalId)->toBeNull()
        ->and($original->versionNumber)->toBe(1)
        ->and($original->rightsMetadata['license'])->toBe('owned')
        ->and($original->observation->observedMediaType)->toBe('image/png')
        ->and($original->observation->width)->toBe(1200)
        ->and($original->observation->height)->toBe(800)
        ->and($replacement->parentOriginalId)->toBe('asset-original-v1')
        ->and($replacement->versionNumber)->toBe(2)
        ->and($replacement->observation->contentSha256)->not->toBe($original->observation->contentSha256)
        ->and($firstPlan->deterministicRequestHash())->toBe($retryPlan->deterministicRequestHash())
        ->and($firstPlan->isReplayEquivalentTo($retryPlan))->toBeTrue();
});

it('certifies accessibility and representative responsive regression findings against exact render identity', function () {
    $input = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        dependencyVersionIds: ['component-v1'],
        bindings: new ResolvedBindings(
            variables: ['first_name' => 'Ada'],
            localized: ['subject' => 'Hello'],
            requestedLocale: 'en',
            localeChain: ['en'],
        ),
        assetReferences: ['asset-original-v1'],
        brandReferences: ['brand-v1'],
    );
    $render = task0036RenderPlan($input);
    $hasher = new CanonicalJsonHasher;
    $preview = (new PreviewPlanner($hasher))->plan(
        $render,
        new PreviewViewport('email-mobile-320', 320, 720, 1),
        new PreviewIsolationPolicy(
            maxDurationMs: 1500,
            maxMemoryMb: 96,
            maxOutputBytes: 750000,
            maxFindings: 20,
        ),
        'phase06-client-regression-v1',
    );

    $findings = [
        new PreviewFinding('accessibility.semantic_structure', 'info', 'Semantic structure validated.', 'root'),
        new PreviewFinding('accessibility.alt_text', 'info', 'Meaningful image alternatives validated.', 'root/image'),
        new PreviewFinding('accessibility.contrast', 'warning', 'Representative contrast requires review.', 'root/text'),
        new PreviewFinding('accessibility.reflow_320', 'info', '320 pixel reflow validated.', 'root'),
        new PreviewFinding('accessibility.text_as_image', 'info', 'Text-as-image concern checked.', 'root/image'),
    ];

    $first = PreviewRegressionResult::fromFindings($preview, $findings, $hasher);
    $reordered = PreviewRegressionResult::fromFindings(
        $preview,
        [$findings[4], $findings[2], $findings[0], $findings[3], $findings[1]],
        $hasher,
    );

    expect($preview->renderArtifactIdentity)->toBe($render->artifactIdentity)
        ->and($first->resultIdentity)->toBe($reordered->resultIdentity)
        ->and($first->status)->toBe('review')
        ->and($first->provenance()['authoritative_source'])->toBeFalse();
});

it('certifies provider synchronization remains capability-versioned replay-safe and conflict oriented', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $mapping = task0036ProviderMapping();
    $observation = task0036ProviderObservation();
    $at = new DateTimeImmutable('2026-09-21T00:10:00+00:00');

    $first = $planner->plan(task0036ProviderRequest('retry-a'), $mapping, task0036ProviderCapability('2026-09'), $observation, $at);
    $retry = $planner->plan(task0036ProviderRequest('retry-b'), $mapping, task0036ProviderCapability('2026-09'), $observation, $at);
    $capabilityChanged = $planner->plan(task0036ProviderRequest('retry-c'), $mapping, task0036ProviderCapability('2026-10'), $observation, $at);

    $synced = $mapping->withSynchronizedState(
        canonicalVersionId: 'template-v1',
        derivativeIdentity: $first->desiredDerivativeIdentity,
        providerFingerprint: $observation->providerFingerprint,
        at: new DateTimeImmutable('2026-09-21T00:11:00+00:00'),
    );
    $external = $planner->plan(
        task0036ProviderRequest('external-review'),
        $synced,
        task0036ProviderCapability(),
        task0036ProviderObservation(str_repeat('c', 64)),
        new DateTimeImmutable('2026-09-21T00:12:00+00:00'),
    );

    expect($first->desiredDerivativeIdentity)->toBe($retry->desiredDerivativeIdentity)
        ->and($first->requestIdentity)->not->toBe($retry->requestIdentity)
        ->and($capabilityChanged->desiredDerivativeIdentity)->not->toBe($first->desiredDerivativeIdentity)
        ->and($external->drift)->toBe(ProviderTemplateDrift::ExternalProviderChange)
        ->and($external->action)->toBe(ProviderTemplateSyncAction::ReviewConflict)
        ->and($external->provenance()['authoritative_source'])->toBeFalse()
        ->and($external->provenance()['live_publish'])->toBeFalse();
});

it('certifies provider synchronization idempotency rejects conflicting exact-version reuse', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $ledger = new InMemoryProviderTemplateSyncLedger;
    $mapping = task0036ProviderMapping();
    $capability = task0036ProviderCapability();
    $observation = task0036ProviderObservation();
    $at = new DateTimeImmutable('2026-09-21T00:10:00+00:00');

    $first = $planner->plan(task0036ProviderRequest('stable-key'), $mapping, $capability, $observation, $at);
    $replay = $planner->plan(task0036ProviderRequest('stable-key'), $mapping, $capability, $observation, $at);

    expect($ledger->claim($first))->toBe($first)
        ->and($ledger->claim($replay))->toBe($first);

    $conflict = $planner->plan(
        task0036ProviderRequest('stable-key', 'template-v2'),
        $mapping,
        $capability,
        $observation,
        $at,
    );

    expect(fn () => $ledger->claim($conflict))
        ->toThrow(InvalidArgumentException::class, 'idempotency key conflicts');
});
