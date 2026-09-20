<?php

use App\Modules\Assets\Infrastructure\Storage\WorkspaceAssetObjectStore;
use App\Modules\Content\Application\Brand\BrandReferenceResolver;
use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Application\DependencyResolution\DependencyResolver;
use App\Modules\Content\Application\Preview\PreviewIsolationPolicy;
use App\Modules\Content\Application\Preview\PreviewPlanner;
use App\Modules\Content\Application\Preview\PreviewViewport;
use App\Modules\Content\Application\Rendering\RenderCompilerPlanner;
use App\Modules\Content\Application\Sanitization\MarkupSanitizer;
use App\Modules\Content\Domain\Authoring\AuthoringTarget;
use App\Modules\Content\Domain\Binding\ResolvedBindings;
use App\Modules\Content\Domain\Brand\BrandKit;
use App\Modules\Content\Domain\Brand\BrandReference;
use App\Modules\Content\Domain\Brand\BrandStyleToken;
use App\Modules\Content\Domain\Brand\BrandTokenKind;
use App\Modules\Content\Domain\Brand\BrandVersion;
use App\Modules\Content\Domain\Brand\BrandVersionStatus;
use App\Modules\Content\Domain\Document\CanonicalNodeType;
use App\Modules\Content\Domain\Document\ContentNode;
use App\Modules\Content\Domain\Render\RendererExecutionPolicy;
use App\Modules\Content\Domain\Render\RendererIdentity;
use App\Modules\Content\Domain\Render\RenderTarget;
use App\Modules\Core\Infrastructure\Storage\LaravelObjectStore;
use App\Modules\Providers\Application\TemplateSync\ProviderTemplateSyncPlanner;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\Templates\ProviderTemplateMapping;
use App\Modules\Providers\Domain\Templates\ProviderTemplateObservation;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncRequest;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

function task0036SecurityInput(string $workspaceId = 'workspace-1'): RenderInputSnapshot
{
    return new RenderInputSnapshot(
        workspaceId: $workspaceId,
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
}

function task0036SecurityDefinition(
    string $id,
    DefinitionKind $kind,
    string $workspaceId,
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
        status: VersionStatus::Published,
        dependencies: $dependencies,
        idempotencyKey: 'idem-'.$id.'-'.$workspaceId,
        createdByActorId: 'security-certifier',
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );
}

function task0036SecurityBrand(string $workspaceId): BrandVersion
{
    $kit = new BrandKit(
        id: 'brand-kit-1',
        workspaceId: $workspaceId,
        name: 'VSN Brand',
        createdByActorId: 'security-certifier',
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    );

    return BrandVersion::initialFor(
        brandKit: $kit,
        id: 'brand-v1',
        styleTokens: [new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039')],
        identityMetadata: ['display_name' => 'VSN'],
        assetReferences: ['asset-original-v1'],
        defaults: ['locale' => 'en'],
        createdByActorId: 'security-certifier',
        auditProvenance: ['source' => 'task0036-security'],
        idempotencyKey: 'brand-v1-'.$workspaceId,
        createdAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
        status: BrandVersionStatus::Published,
    );
}

it('rejects executable provider and raw markup from canonical content and authoring inputs', function () {
    expect(fn () => new ContentNode(
        nodeId: 'unsafe-text',
        type: CanonicalNodeType::Text,
        properties: [
            'text' => 'Unsafe',
            'style' => ['provider_payload' => '<script>alert(1)</script>'],
        ],
    ))->toThrow(InvalidArgumentException::class, 'Provider/render executable property is forbidden');

    $sanitizer = new MarkupSanitizer;

    foreach ([
        '<script>alert(1)</script>',
        '<p onclick="alert(1)">unsafe</p>',
        '<a href="javascript:alert(1)">unsafe</a>',
        '<p style="background:url(https://attacker.example/pixel)">unsafe</p>',
        '<img src="https://attacker.example/pixel.png" alt="remote">',
    ] as $payload) {
        expect(fn () => $sanitizer->sanitize($payload, AuthoringTarget::Email))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects renderer traversal remote fetch credential and shell configuration while preview stays deny by default', function () {
    $planner = new RenderCompilerPlanner(new CanonicalJsonHasher);
    $renderer = new RendererIdentity('vsn-email-compiler', '1.2.3');
    $policy = new RendererExecutionPolicy(
        maxDurationMs: 2000,
        maxMemoryMb: 128,
        maxOutputBytes: 1000000,
        maxInputNodes: 3000,
    );

    foreach ([
        ['api_key' => 'secret'],
        ['network' => true],
        ['command' => 'wkhtmltopdf'],
        ['stylesheet' => 'https://attacker.example/style.css'],
        ['template' => '../../etc/passwd'],
        ['source' => 'file:///tmp/template.html'],
    ] as $configuration) {
        expect(fn () => $planner->plan(
            input: task0036SecurityInput(),
            target: RenderTarget::EmailHtml,
            renderer: $renderer,
            executionPolicy: $policy,
            configuration: $configuration,
        ))->toThrow(InvalidArgumentException::class);
    }

    $render = $planner->plan(
        input: task0036SecurityInput(),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: $policy,
        configuration: ['minify' => true],
    );
    $previewPolicy = new PreviewIsolationPolicy(
        maxDurationMs: 1500,
        maxMemoryMb: 96,
        maxOutputBytes: 750000,
        maxFindings: 20,
    );
    $preview = (new PreviewPlanner(new CanonicalJsonHasher))->plan(
        $render,
        new PreviewViewport('mobile', 320, 720, 1),
        $previewPolicy,
        'phase06-security-v1',
    );

    expect($render->executionPolicy->toArray())->toMatchArray([
        'network_access' => false,
        'filesystem_access' => false,
        'shell_access' => false,
        'ambient_secret_access' => false,
    ])->and($previewPolicy->toArray())->toMatchArray([
        'network_access' => false,
        'filesystem_access' => false,
        'shell_access' => false,
        'ambient_secret_access' => false,
        'canonical_state_mutation' => false,
        'provider_publishing' => false,
    ])->and($preview->provenance()['authoritative_source'])->toBeFalse();

    expect(fn () => (new PreviewPlanner(new CanonicalJsonHasher))->plan(
        $render,
        new PreviewViewport('mobile', 320, 720, 1),
        new PreviewIsolationPolicy(maxDurationMs: 2500),
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer duration limit');
});

it('fails closed for cross-workspace dependencies and exact brand references', function () {
    $root = task0036SecurityDefinition(
        'template-v1',
        DefinitionKind::Template,
        'workspace-1',
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );
    $foreignComponent = task0036SecurityDefinition('component-v1', DefinitionKind::Component, 'workspace-2');

    expect(fn () => (new DependencyResolver)->resolve($root, [$foreignComponent]))
        ->toThrow(InvalidArgumentException::class, 'Resolved dependency belongs to another workspace');

    $foreignBrand = task0036SecurityBrand('workspace-2');

    expect(fn () => (new BrandReferenceResolver)->resolve(
        'workspace-1',
        [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1')],
        [$foreignBrand],
    ))->toThrow(InvalidArgumentException::class, 'belongs to another workspace');
});

it('fails closed for asset traversal foreign workspace storage and destructive canonical operations', function () {
    Storage::fake('local');

    $rawStore = new LaravelObjectStore(app(FilesystemManager::class), 'local');
    $store = new WorkspaceAssetObjectStore($rawStore);
    $workspaceId = 'workspace-1';
    $foreignWorkspaceId = 'workspace-2';
    $contents = 'phase06-security-object';
    $hash = hash('sha256', $contents);

    expect(fn () => $store->putImmutable(
        $workspaceId,
        '../escape.bin',
        $contents,
        $hash,
    ))->toThrow(InvalidArgumentException::class, 'unsafe path')
        ->and(fn () => $store->putImmutable(
            $workspaceId,
            'workspaces/'.$foreignWorkspaceId.'/assets/originals/foreign.bin',
            $contents,
            $hash,
        ))->toThrow(AuthorizationException::class, 'outside the requested workspace boundary');

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(WorkspaceAssetObjectStore::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->not->toContain('delete')
        ->not->toContain('move')
        ->not->toContain('copyFromUrl')
        ->not->toContain('fetchRemote');
});

it('fails closed for provider workspace crossings and sensitive capability evidence without exposing publication authority', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = new ProviderTemplateSyncRequest(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        canonicalTemplateVersionId: 'template-v1',
        canonicalSourceIdentity: str_repeat('a', 64),
        componentVersionIds: ['component-v1'],
        brandVersionIds: ['brand-v1'],
        templateKind: 'email',
        mediaKinds: ['image'],
        idempotencyKey: 'sync-1',
    );
    $mapping = new ProviderTemplateMapping(
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
    $observation = new ProviderTemplateObservation(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        providerTemplateReference: 'provider-template-42',
        providerFingerprint: str_repeat('b', 64),
        sourceVersion: 'provider-api-v3',
        observedAt: new DateTimeImmutable('2026-09-21T00:05:00+00:00'),
    );

    $foreignCapability = new ProviderCapability(
        id: 'capability-foreign',
        workspaceId: 'workspace-2',
        providerId: 'provider-1',
        connectionId: 'connection-secret',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'media_kinds' => ['image']],
        sourceUrl: 'https://example.test/provider-docs',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-21T00:00:00+00:00'),
    );

    expect(fn () => $planner->plan(
        $request,
        $mapping,
        $foreignCapability,
        $observation,
        new DateTimeImmutable('2026-09-21T00:10:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot cross workspace or provider boundaries');

    $unsafeCapability = new ProviderCapability(
        id: 'capability-unsafe',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        connectionId: 'connection-secret',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'authorization_token' => 'secret'],
        sourceUrl: 'https://example.test/provider-docs',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-21T00:00:00+00:00'),
    );

    expect(fn () => $planner->plan(
        $request,
        $mapping,
        $unsafeCapability,
        $observation,
        new DateTimeImmutable('2026-09-21T00:10:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive provider template capability key is forbidden');
});
