<?php

use App\Modules\Assets\Domain\Asset\AssetKind;
use App\Modules\Assets\Domain\Asset\AssetLifecycle;
use App\Modules\Assets\Domain\Asset\CanonicalAsset;
use App\Modules\Assets\Infrastructure\Persistence\DatabaseAssetRepository;
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
use App\Modules\Content\Domain\Render\RenderCompilationPlan;
use App\Modules\Content\Domain\Render\RendererExecutionPolicy;
use App\Modules\Content\Domain\Render\RendererIdentity;
use App\Modules\Content\Domain\Render\RenderTarget;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) === false) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0034 final integration certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0034 final integration certification requires PostgreSQL.');
    }
});

function task0034CertificationWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $unique = Str::lower(Str::random(8));
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0034 '.$suffix,
        'slug' => 'task0034-cert-'.$suffix.'-'.$unique,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0034 Certification '.$suffix,
        'slug' => 'task0034-cert-workspace-'.$suffix.'-'.$unique,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

/** @param  list<DependencyReference>  $dependencies */
function task0034CertificationDefinition(
    string $id,
    DefinitionKind $kind,
    string $workspaceId,
    array $dependencies = [],
): VersionedDefinition {
    return new VersionedDefinition(
        id: $id,
        workspaceId: $workspaceId,
        kind: $kind,
        ownerId: 'owner-'.$id,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Published,
        dependencies: $dependencies,
        idempotencyKey: 'idem-'.$id,
        createdByActorId: 'task0034-certifier',
        createdAt: new DateTimeImmutable('2026-09-19T12:00:00+00:00'),
    );
}

/**
 * @param  list<string>  $dependencyVersionIds
 * @param  list<string>  $assetReferences
 */
function task0034CertificationSnapshot(
    string $workspaceId,
    string $contentVersionId = 'content-v1',
    array $dependencyVersionIds = ['component-v1'],
    array $assetReferences = ['asset-v1'],
): RenderInputSnapshot {
    return new RenderInputSnapshot(
        workspaceId: $workspaceId,
        contentVersionId: $contentVersionId,
        templateVersionId: 'template-v1',
        dependencyVersionIds: $dependencyVersionIds,
        bindings: new ResolvedBindings(
            variables: ['first_name' => 'Ada', 'count' => 2],
            localized: ['subject' => 'Hello'],
            requestedLocale: 'en',
            localeChain: ['en'],
        ),
        assetReferences: $assetReferences,
        brandReferences: ['brand-v1'],
    );
}

/** @param  array<string, mixed>  $configuration */
function task0034CertificationRenderPlan(
    RenderInputSnapshot $snapshot,
    array $configuration = ['minify' => true, 'doctype' => 'html5'],
): RenderCompilationPlan {
    return (new RenderCompilerPlanner(new CanonicalJsonHasher))->plan(
        input: $snapshot,
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('vsn-email-compiler', '1.2.3'),
        executionPolicy: new RendererExecutionPolicy(
            maxDurationMs: 3000,
            maxMemoryMb: 128,
            maxOutputBytes: 1000000,
            maxInputNodes: 3000,
        ),
        configuration: $configuration,
    );
}

it('replays exact render preview and regression inputs deterministically', function () {
    $workspaceId = task0034CertificationWorkspace('determinism');
    $hasher = new CanonicalJsonHasher;

    $firstInput = task0034CertificationSnapshot(
        workspaceId: $workspaceId,
        dependencyVersionIds: ['template-v1', 'component-v1'],
        assetReferences: ['asset-v2', 'asset-v1'],
    );
    $equivalentInput = task0034CertificationSnapshot(
        workspaceId: $workspaceId,
        dependencyVersionIds: ['component-v1', 'template-v1'],
        assetReferences: ['asset-v1', 'asset-v2'],
    );

    $firstRender = task0034CertificationRenderPlan(
        $firstInput,
        ['minify' => true, 'doctype' => 'html5'],
    );
    $equivalentRender = task0034CertificationRenderPlan(
        $equivalentInput,
        ['doctype' => 'html5', 'minify' => true],
    );

    $previewPlanner = new PreviewPlanner($hasher);
    $viewport = new PreviewViewport('reflow-320', 320, 720, 1);
    $isolation = new PreviewIsolationPolicy(
        maxDurationMs: 1500,
        maxMemoryMb: 96,
        maxOutputBytes: 750000,
        maxFindings: 20,
    );

    $firstPreview = $previewPlanner->plan($firstRender, $viewport, $isolation, 'task0034-regression-v1');
    $equivalentPreview = $previewPlanner->plan($equivalentRender, $viewport, $isolation, 'task0034-regression-v1');

    $contrast = new PreviewFinding(
        code: 'accessibility.contrast_review',
        severity: 'warning',
        message: 'Representative rendered contrast requires review.',
        path: 'root/section/text',
    );
    $reflow = new PreviewFinding(
        code: 'accessibility.reflow_320',
        severity: 'info',
        message: 'Representative 320 pixel reflow check completed.',
        path: 'root/section',
    );

    $firstResult = PreviewRegressionResult::fromFindings($firstPreview, [$contrast, $reflow], $hasher);
    $replayedResult = PreviewRegressionResult::fromFindings($equivalentPreview, [$reflow, $contrast], $hasher);

    expect($firstInput->identity($hasher))->toBe($equivalentInput->identity($hasher))
        ->and($firstRender->artifactIdentity)->toBe($equivalentRender->artifactIdentity)
        ->and($firstPreview->previewIdentity)->toBe($equivalentPreview->previewIdentity)
        ->and($firstResult->resultIdentity)->toBe($replayedResult->resultIdentity)
        ->and($firstResult->status)->toBe('review')
        ->and($firstResult->provenance()['authoritative_source'])->toBeFalse();
});

it('changes derivative identities when exact source workspace or asset inputs change', function () {
    $workspaceA = task0034CertificationWorkspace('identity-a');
    $workspaceB = task0034CertificationWorkspace('identity-b');
    $hasher = new CanonicalJsonHasher;

    $base = task0034CertificationSnapshot($workspaceA);
    $changedContent = task0034CertificationSnapshot($workspaceA, contentVersionId: 'content-v2');
    $changedAsset = task0034CertificationSnapshot($workspaceA, assetReferences: ['asset-v2']);
    $changedWorkspace = task0034CertificationSnapshot($workspaceB);

    $baseRender = task0034CertificationRenderPlan($base);
    $basePreview = (new PreviewPlanner($hasher))->plan(
        $baseRender,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy,
        'task0034-regression-v1',
    );

    expect($changedContent->identity($hasher))->not->toBe($base->identity($hasher))
        ->and($changedAsset->identity($hasher))->not->toBe($base->identity($hasher))
        ->and($changedWorkspace->identity($hasher))->not->toBe($base->identity($hasher))
        ->and($baseRender->artifactIdentity)->toMatch('/^[a-f0-9]{64}$/')
        ->and($basePreview->previewIdentity)->toMatch('/^[a-f0-9]{64}$/');
});

it('fails closed for cross-workspace exact dependencies and canonical asset resolution', function () {
    $workspaceA = task0034CertificationWorkspace('scope-a');
    $workspaceB = task0034CertificationWorkspace('scope-b');

    $root = task0034CertificationDefinition(
        id: 'template-root',
        kind: DefinitionKind::Template,
        workspaceId: $workspaceA,
        dependencies: [
            new DependencyReference($workspaceA, DependencyKind::ComponentVersion, 'component-v1'),
        ],
    );
    $component = task0034CertificationDefinition(
        id: 'component-v1',
        kind: DefinitionKind::Component,
        workspaceId: $workspaceA,
    );
    $foreignComponent = task0034CertificationDefinition(
        id: 'component-v1',
        kind: DefinitionKind::Component,
        workspaceId: $workspaceB,
    );

    $resolved = (new DependencyResolver)->resolve($root, [$component]);

    expect($resolved->versionIds())->toBe(['component-v1'])
        ->and(fn () => (new DependencyResolver)->resolve($root, [$foreignComponent]))
        ->toThrow(InvalidArgumentException::class, 'Resolved dependency belongs to another workspace');

    $asset = new CanonicalAsset(
        id: (string) Str::uuid(),
        workspaceId: $workspaceA,
        name: 'TASK-0034 exact render asset',
        kind: AssetKind::Image,
        lifecycle: AssetLifecycle::Draft,
        createdByActorId: 'task0034-certifier',
        auditProvenance: ['source' => 'task0034-render-certification'],
        createdAt: new DateTimeImmutable('2026-09-19T12:10:00+00:00'),
    );
    $assets = app(DatabaseAssetRepository::class);
    $assets->createAsset($asset);

    expect($assets->findAsset($workspaceA, $asset->id)?->id)->toBe($asset->id)
        ->and(fn () => $assets->findAsset($workspaceB, $asset->id))
        ->toThrow(AuthorizationException::class, 'Canonical asset access denied');

    $snapshot = task0034CertificationSnapshot(
        workspaceId: $workspaceA,
        assetReferences: [$asset->id],
    );

    expect($snapshot->assetReferences)->toBe([$asset->id]);
});

it('keeps preview limits within renderer limits and derivative provenance privilege free', function () {
    $workspaceId = task0034CertificationWorkspace('isolation');
    $renderPlan = task0034CertificationRenderPlan(task0034CertificationSnapshot($workspaceId));
    $previewPolicy = new PreviewIsolationPolicy(
        maxDurationMs: 1500,
        maxMemoryMb: 96,
        maxOutputBytes: 750000,
    );
    $previewPlanner = new PreviewPlanner(new CanonicalJsonHasher);
    $preview = $previewPlanner->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        $previewPolicy,
    );

    expect($renderPlan->executionPolicy->toArray())->toMatchArray([
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

    expect(fn () => $previewPlanner->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxDurationMs: 3500),
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer duration limit');
});
