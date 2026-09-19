<?php

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Application\Preview\PreviewExecutionPlan;
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

function task0034PreviewInput(
    string $workspaceId = 'workspace-1',
    string $contentVersionId = 'content-v1',
): RenderInputSnapshot {
    return new RenderInputSnapshot(
        workspaceId: $workspaceId,
        contentVersionId: $contentVersionId,
        templateVersionId: 'template-v1',
        dependencyVersionIds: ['component-v1'],
        bindings: new ResolvedBindings(
            variables: ['first_name' => 'Ada'],
            localized: ['subject' => 'Hello'],
            requestedLocale: 'en',
            localeChain: ['en'],
        ),
        assetReferences: ['asset-version-v1'],
        brandReferences: ['brand-v1'],
    );
}

function task0034PreviewRenderPlan(
    string $workspaceId = 'workspace-1',
    string $contentVersionId = 'content-v1',
    ?RendererExecutionPolicy $policy = null,
): RenderCompilationPlan {
    return (new RenderCompilerPlanner(new CanonicalJsonHasher))->plan(
        input: task0034PreviewInput($workspaceId, $contentVersionId),
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('vsn-email-compiler', '1.2.3'),
        executionPolicy: $policy ?? new RendererExecutionPolicy,
        configuration: ['minify' => true],
    );
}

function task0034PreviewPlanner(): PreviewPlanner
{
    return new PreviewPlanner(new CanonicalJsonHasher);
}

it('creates deterministic non-authoritative preview identity from exact render artifact viewport profile and isolation policy', function () {
    $planner = task0034PreviewPlanner();
    $renderPlan = task0034PreviewRenderPlan();
    $viewport = new PreviewViewport('desktop', 1440, 900, 2);
    $policy = new PreviewIsolationPolicy(
        maxDurationMs: 1500,
        maxMemoryMb: 96,
        maxOutputBytes: 750000,
        maxFindings: 50,
    );

    $first = $planner->plan($renderPlan, $viewport, $policy, 'email-regression-v1');
    $replayed = $planner->plan($renderPlan, $viewport, $policy, 'email-regression-v1');

    expect($first->previewIdentity)->toBe($replayed->previewIdentity)
        ->and($first->provenance())->toMatchArray([
            'workspace_id' => 'workspace-1',
            'render_artifact_identity' => $renderPlan->artifactIdentity,
            'render_input_identity' => $renderPlan->renderInputIdentity,
            'render_target' => 'email_html',
            'regression_profile_version' => 'email-regression-v1',
            'authoritative_source' => false,
        ])
        ->and($first->provenance()['viewport'])->toBe([
            'name' => 'desktop',
            'width' => 1440,
            'height' => 900,
            'device_scale_factor' => 2,
        ]);
});

it('changes preview identity when pinned render source viewport or regression profile changes', function () {
    $planner = task0034PreviewPlanner();
    $policy = new PreviewIsolationPolicy;
    $base = $planner->plan(
        task0034PreviewRenderPlan(),
        new PreviewViewport('desktop', 1440, 900),
        $policy,
        'profile-v1',
    );

    $changedSource = $planner->plan(
        task0034PreviewRenderPlan(contentVersionId: 'content-v2'),
        new PreviewViewport('desktop', 1440, 900),
        $policy,
        'profile-v1',
    );
    $changedViewport = $planner->plan(
        task0034PreviewRenderPlan(),
        new PreviewViewport('mobile', 390, 844),
        $policy,
        'profile-v1',
    );
    $changedProfile = $planner->plan(
        task0034PreviewRenderPlan(),
        new PreviewViewport('desktop', 1440, 900),
        $policy,
        'profile-v2',
    );
    $changedWorkspace = $planner->plan(
        task0034PreviewRenderPlan(workspaceId: 'workspace-2'),
        new PreviewViewport('desktop', 1440, 900),
        $policy,
        'profile-v1',
    );

    expect($changedSource->previewIdentity)->not->toBe($base->previewIdentity)
        ->and($changedViewport->previewIdentity)->not->toBe($base->previewIdentity)
        ->and($changedProfile->previewIdentity)->not->toBe($base->previewIdentity)
        ->and($changedWorkspace->previewIdentity)->not->toBe($base->previewIdentity);
});

it('denies ambient privilege canonical mutation and publishing and cannot exceed renderer resource limits', function () {
    $planner = task0034PreviewPlanner();
    $renderPlan = task0034PreviewRenderPlan(
        policy: new RendererExecutionPolicy(
            maxDurationMs: 2000,
            maxMemoryMb: 128,
            maxOutputBytes: 1000000,
            maxInputNodes: 3000,
        ),
    );
    $policy = new PreviewIsolationPolicy(
        maxDurationMs: 1500,
        maxMemoryMb: 96,
        maxOutputBytes: 750000,
    );

    expect($policy->toArray())->toMatchArray([
        'network_access' => false,
        'filesystem_access' => false,
        'shell_access' => false,
        'ambient_secret_access' => false,
        'canonical_state_mutation' => false,
        'provider_publishing' => false,
    ]);

    expect(fn () => $planner->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxDurationMs: 2500, maxMemoryMb: 96, maxOutputBytes: 750000),
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer duration limit');

    expect(fn () => $planner->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxDurationMs: 1500, maxMemoryMb: 192, maxOutputBytes: 750000),
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer memory limit');

    expect(fn () => $planner->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxDurationMs: 1500, maxMemoryMb: 96, maxOutputBytes: 1000001),
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer output limit');
});

it('normalizes regression findings deterministically and binds results to exact preview and render identities', function () {
    $hasher = new CanonicalJsonHasher;
    $plan = task0034PreviewPlanner()->plan(
        task0034PreviewRenderPlan(),
        new PreviewViewport('desktop', 1440, 900),
        new PreviewIsolationPolicy(maxFindings: 10),
    );

    $warning = new PreviewFinding(
        code: 'accessibility.low_contrast',
        severity: 'warning',
        message: 'Text contrast requires review.',
        path: 'root/section/text',
    );
    $error = new PreviewFinding(
        code: 'layout.horizontal_overflow',
        severity: 'error',
        message: 'Rendered content exceeds the viewport width.',
        path: 'root/section',
    );

    $first = PreviewRegressionResult::fromFindings($plan, [$warning, $error], $hasher);
    $reordered = PreviewRegressionResult::fromFindings($plan, [$error, $warning], $hasher);

    expect($first->status)->toBe('failed')
        ->and($first->resultIdentity)->toBe($reordered->resultIdentity)
        ->and($first->provenance()['findings'])->toBe($reordered->provenance()['findings'])
        ->and($first->provenance())->toMatchArray([
            'workspace_id' => 'workspace-1',
            'preview_identity' => $plan->previewIdentity,
            'render_artifact_identity' => $plan->renderArtifactIdentity,
            'render_input_identity' => $plan->renderInputIdentity,
            'authoritative_source' => false,
        ]);
});

it('derives passed review and failed regression statuses without raw rendered output', function () {
    $hasher = new CanonicalJsonHasher;
    $plan = task0034PreviewPlanner()->plan(
        task0034PreviewRenderPlan(),
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy,
    );

    $passed = PreviewRegressionResult::fromFindings(
        $plan,
        [new PreviewFinding('a11y.alt_text_present', 'info', 'Image text alternative is present.')],
        $hasher,
    );
    $review = PreviewRegressionResult::fromFindings(
        $plan,
        [new PreviewFinding('a11y.contrast_review', 'warning', 'Contrast requires review.')],
        $hasher,
    );
    $failed = PreviewRegressionResult::fromFindings(
        $plan,
        [new PreviewFinding('layout.overflow', 'error', 'Layout overflows the viewport.')],
        $hasher,
    );

    $encoded = json_encode($failed->provenance(), JSON_THROW_ON_ERROR);

    expect($passed->status)->toBe('passed')
        ->and($review->status)->toBe('review')
        ->and($failed->status)->toBe('failed')
        ->and($encoded)->not->toContain('provider_payload')
        ->and($encoded)->not->toContain('credential')
        ->and($encoded)->not->toContain('raw_output')
        ->and($encoded)->not->toContain('<html');
});

it('fails closed on invalid preview bounds profiles findings duplicates and result limits', function () {
    expect(fn () => new PreviewViewport('desktop viewport', 1280, 720))
        ->toThrow(InvalidArgumentException::class, 'viewport name');
    expect(fn () => new PreviewViewport('desktop', 100, 720))
        ->toThrow(InvalidArgumentException::class, 'viewport width');
    expect(fn () => new PreviewIsolationPolicy(maxFindings: 0))
        ->toThrow(InvalidArgumentException::class, 'finding limit');

    $renderPlan = task0034PreviewRenderPlan();
    expect(fn () => task0034PreviewPlanner()->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy,
        'profile with spaces',
    ))->toThrow(InvalidArgumentException::class, 'regression profile version');

    $plan = task0034PreviewPlanner()->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxFindings: 1),
    );
    $finding = new PreviewFinding('layout.overflow', 'error', 'Layout overflows the viewport.');

    expect(fn () => PreviewRegressionResult::fromFindings(
        $plan,
        [$finding, new PreviewFinding('a11y.contrast', 'warning', 'Contrast requires review.')],
        new CanonicalJsonHasher,
    ))->toThrow(InvalidArgumentException::class, 'exceed the pinned isolation policy limit');

    $duplicatePlan = task0034PreviewPlanner()->plan(
        $renderPlan,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxFindings: 2),
    );
    expect(fn () => PreviewRegressionResult::fromFindings(
        $duplicatePlan,
        [$finding, $finding],
        new CanonicalJsonHasher,
    ))->toThrow(InvalidArgumentException::class, 'cannot contain exact duplicates');
});

it('keeps preview execution plan hash shaped and derivative', function () {
    $plan = task0034PreviewPlanner()->plan(
        task0034PreviewRenderPlan(),
        new PreviewViewport('mobile', 390, 844, 3),
        new PreviewIsolationPolicy,
    );

    expect($plan)->toBeInstanceOf(PreviewExecutionPlan::class)
        ->and($plan->previewIdentity)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->renderArtifactIdentity)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->renderInputIdentity)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->provenance()['authoritative_source'])->toBeFalse();
});
