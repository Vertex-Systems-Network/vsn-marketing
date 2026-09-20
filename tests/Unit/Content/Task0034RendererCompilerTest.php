<?php

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Application\Rendering\RenderCompilerPlanner;
use App\Modules\Content\Domain\Binding\ResolvedBindings;
use App\Modules\Content\Domain\Render\RendererExecutionPolicy;
use App\Modules\Content\Domain\Render\RendererIdentity;
use App\Modules\Content\Domain\Render\RenderTarget;

function task0034RenderInput(
    string $contentVersionId = 'content-v1',
    array $dependencyVersionIds = ['component-v2', 'component-v1'],
    array $assetReferences = ['asset-v2', 'asset-v1'],
): RenderInputSnapshot {
    return new RenderInputSnapshot(
        workspaceId: 'workspace-1',
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

function task0034RendererPlanner(): RenderCompilerPlanner
{
    return new RenderCompilerPlanner(new CanonicalJsonHasher);
}

it('creates deterministic artifact identity from exact render input renderer config and bounded policy', function () {
    $planner = task0034RendererPlanner();
    $renderer = new RendererIdentity('vsn-email-compiler', '1.2.3');
    $policy = new RendererExecutionPolicy(
        maxDurationMs: 4000,
        maxMemoryMb: 192,
        maxOutputBytes: 2000000,
        maxInputNodes: 3000,
    );

    $first = $planner->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: $policy,
        configuration: [
            'features' => ['semantic_html', 'inline_css'],
            'doctype' => 'html5',
            'minify' => true,
        ],
    );

    $equivalent = $planner->plan(
        input: task0034RenderInput(
            dependencyVersionIds: ['component-v1', 'component-v2'],
            assetReferences: ['asset-v1', 'asset-v2'],
        ),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: $policy,
        configuration: [
            'minify' => true,
            'doctype' => 'html5',
            'features' => ['semantic_html', 'inline_css'],
        ],
    );

    expect($first->artifactIdentity)->toBe($equivalent->artifactIdentity)
        ->and($first->renderInputIdentity)->toBe($equivalent->renderInputIdentity)
        ->and($first->configurationHash)->toBe($equivalent->configurationHash)
        ->and($first->provenance())->toMatchArray([
            'workspace_id' => 'workspace-1',
            'target' => 'email_html',
            'media_type' => 'text/html; charset=utf-8',
            'renderer' => [
                'id' => 'vsn-email-compiler',
                'version' => '1.2.3',
                'configuration_sha256' => $first->configurationHash,
            ],
            'authoritative_source' => false,
        ]);
});

it('changes artifact identity when exact source renderer configuration target or policy changes', function () {
    $planner = task0034RendererPlanner();
    $renderer = new RendererIdentity('vsn-email-compiler', '1.2.3');
    $base = $planner->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: new RendererExecutionPolicy,
        configuration: ['minify' => true],
    );

    $changedSource = $planner->plan(
        input: task0034RenderInput(contentVersionId: 'content-v2'),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: new RendererExecutionPolicy,
        configuration: ['minify' => true],
    );
    $changedRenderer = $planner->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('vsn-email-compiler', '1.2.4'),
        executionPolicy: new RendererExecutionPolicy,
        configuration: ['minify' => true],
    );
    $changedConfig = $planner->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: new RendererExecutionPolicy,
        configuration: ['minify' => false],
    );
    $changedTarget = $planner->plan(
        input: task0034RenderInput(),
        target: RenderTarget::WebHtml,
        renderer: $renderer,
        executionPolicy: new RendererExecutionPolicy,
        configuration: ['minify' => true],
    );
    $changedPolicy = $planner->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: new RendererExecutionPolicy(maxDurationMs: 6000),
        configuration: ['minify' => true],
    );

    expect($changedSource->artifactIdentity)->not->toBe($base->artifactIdentity)
        ->and($changedRenderer->artifactIdentity)->not->toBe($base->artifactIdentity)
        ->and($changedConfig->artifactIdentity)->not->toBe($base->artifactIdentity)
        ->and($changedTarget->artifactIdentity)->not->toBe($base->artifactIdentity)
        ->and($changedPolicy->artifactIdentity)->not->toBe($base->artifactIdentity);
});

it('denies renderer ambient network filesystem shell and secret access by contract', function () {
    $policy = new RendererExecutionPolicy;

    expect($policy->toArray())->toMatchArray([
        'network_access' => false,
        'filesystem_access' => false,
        'shell_access' => false,
        'ambient_secret_access' => false,
    ]);

    expect(fn () => new RendererExecutionPolicy(maxDurationMs: 31_000))
        ->toThrow(InvalidArgumentException::class, 'duration limit');
    expect(fn () => new RendererExecutionPolicy(maxMemoryMb: 2048))
        ->toThrow(InvalidArgumentException::class, 'memory limit');
    expect(fn () => new RendererExecutionPolicy(maxOutputBytes: 10_485_761))
        ->toThrow(InvalidArgumentException::class, 'output limit');
    expect(fn () => new RendererExecutionPolicy(maxInputNodes: 10_001))
        ->toThrow(InvalidArgumentException::class, 'input node limit');
});

it('rejects privileged provider-specific remote and filesystem renderer configuration', function () {
    $planner = task0034RendererPlanner();
    $input = task0034RenderInput();
    $renderer = new RendererIdentity('vsn-email-compiler', '1.2.3');
    $policy = new RendererExecutionPolicy;

    foreach ([
        ['api_key' => 'secret'],
        ['provider_payload' => ['mime' => 'raw']],
        ['network' => true],
        ['include_path' => 'templates'],
        ['command' => 'wkhtmltopdf'],
    ] as $configuration) {
        expect(fn () => $planner->plan(
            input: $input,
            target: RenderTarget::EmailHtml,
            renderer: $renderer,
            executionPolicy: $policy,
            configuration: $configuration,
        ))->toThrow(InvalidArgumentException::class, 'privileged or provider-specific capability');
    }

    foreach ([
        ['stylesheet' => 'https://example.com/style.css'],
        ['template' => '/etc/passwd'],
        ['source' => 'file:///tmp/template.html'],
        ['source' => '//example.com/template'],
    ] as $configuration) {
        expect(fn () => $planner->plan(
            input: $input,
            target: RenderTarget::EmailHtml,
            renderer: $renderer,
            executionPolicy: $policy,
            configuration: $configuration,
        ))->toThrow(InvalidArgumentException::class, 'external or filesystem resources');
    }
});

it('rejects relative plain windows and encoded renderer path traversal', function () {
    $planner = task0034RendererPlanner();
    $input = task0034RenderInput();
    $renderer = new RendererIdentity('vsn-email-compiler', '1.2.3');
    $policy = new RendererExecutionPolicy;

    foreach ([
        ['template' => '../../etc/passwd'],
        ['template' => 'partials/../../secrets.env'],
        ['template' => '..\\..\\Windows\\System32\\config'],
        ['template' => '%2e%2e/%2e%2e/secrets.env'],
        ['template' => '%252e%252e%252fsecret'],
    ] as $configuration) {
        expect(fn () => $planner->plan(
            input: $input,
            target: RenderTarget::EmailHtml,
            renderer: $renderer,
            executionPolicy: $policy,
            configuration: $configuration,
        ))->toThrow(InvalidArgumentException::class, 'external or filesystem resources');
    }
});

it('keeps render plans derivative and free of provider payloads credentials and raw rendered output', function () {
    $plan = task0034RendererPlanner()->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('vsn-email-compiler', '1.2.3'),
        executionPolicy: new RendererExecutionPolicy,
        configuration: ['minify' => true],
    );

    $encoded = json_encode($plan->provenance(), JSON_THROW_ON_ERROR);

    expect($plan->provenance()['authoritative_source'])->toBeFalse()
        ->and($encoded)->not->toContain('provider_payload')
        ->and($encoded)->not->toContain('api_key')
        ->and($encoded)->not->toContain('credential')
        ->and($encoded)->not->toContain('<html')
        ->and($encoded)->not->toContain('raw_output');
});

it('requires bounded stable renderer identity and immutable hash-shaped provenance', function () {
    expect(fn () => new RendererIdentity('renderer id with spaces', '1.0.0'))
        ->toThrow(InvalidArgumentException::class, 'Renderer id');

    expect(fn () => new RendererIdentity('renderer', 'version with spaces'))
        ->toThrow(InvalidArgumentException::class, 'Renderer version');

    $plan = task0034RendererPlanner()->plan(
        input: task0034RenderInput(),
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('renderer', '1.0.0'),
        executionPolicy: new RendererExecutionPolicy,
    );

    expect($plan->artifactIdentity)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->renderInputIdentity)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->configurationHash)->toMatch('/^[a-f0-9]{64}$/');
});
