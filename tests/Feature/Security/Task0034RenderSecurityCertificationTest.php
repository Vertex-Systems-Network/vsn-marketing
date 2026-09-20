<?php

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Application\Preview\PreviewFinding;
use App\Modules\Content\Application\Preview\PreviewIsolationPolicy;
use App\Modules\Content\Application\Preview\PreviewPlanner;
use App\Modules\Content\Application\Preview\PreviewRegressionResult;
use App\Modules\Content\Application\Preview\PreviewViewport;
use App\Modules\Content\Application\Rendering\RenderCompilerPlanner;
use App\Modules\Content\Application\Sanitization\MarkupSanitizer;
use App\Modules\Content\Domain\Authoring\AuthoringTarget;
use App\Modules\Content\Domain\Binding\ResolvedBindings;
use App\Modules\Content\Domain\Render\RenderCompilationPlan;
use App\Modules\Content\Domain\Render\RendererExecutionPolicy;
use App\Modules\Content\Domain\Render\RendererIdentity;
use App\Modules\Content\Domain\Render\RenderTarget;

function task0034SecurityInput(string $workspaceId = 'workspace-security'): RenderInputSnapshot
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
        assetReferences: ['asset-v1'],
        brandReferences: ['brand-v1'],
    );
}

/** @param array<string, mixed> $configuration */
function task0034SecurityRenderPlan(array $configuration = ['minify' => true]): RenderCompilationPlan
{
    return (new RenderCompilerPlanner(new CanonicalJsonHasher))->plan(
        input: task0034SecurityInput(),
        target: RenderTarget::EmailHtml,
        renderer: new RendererIdentity('vsn-email-compiler', '1.2.3'),
        executionPolicy: new RendererExecutionPolicy(
            maxDurationMs: 2000,
            maxMemoryMb: 128,
            maxOutputBytes: 1000000,
            maxInputNodes: 3000,
        ),
        configuration: $configuration,
    );
}

it('rejects active markup handlers unsafe urls css fetches remote media and parser declarations', function () {
    $sanitizer = new MarkupSanitizer;

    foreach ([
        '<script>alert(1)</script>',
        '<iframe><p>unsafe</p></iframe>',
        '<svg><script>alert(1)</script></svg>',
        '<p onclick="alert(1)">unsafe</p>',
        '<a href="java&#x73;cript:alert(1)">unsafe</a>',
        '<p style="background-image:url(https://attacker.example/pixel)">unsafe</p>',
        '<img src="https://attacker.example/pixel.png" alt="remote">',
        '<!DOCTYPE html><p>unsafe</p>',
        '<?xml version="1.0"?><p>unsafe</p>',
    ] as $payload) {
        expect(fn () => $sanitizer->sanitize($payload, AuthoringTarget::Email))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('does not trust post sanitization mutation', function () {
    $sanitizer = new MarkupSanitizer;
    $safe = $sanitizer->sanitize(
        '<p class="message">Safe <strong>content</strong></p>',
        AuthoringTarget::Email,
    );

    expect($safe->markup)->toBe('<p class="message">Safe <strong>content</strong></p>')
        ->and(fn () => $sanitizer->sanitize(
            $safe->markup.'<script>alert(1)</script>',
            AuthoringTarget::Email,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $sanitizer->sanitize(
            str_replace('<p ', '<p onclick="alert(1)" ', $safe->markup),
            AuthoringTarget::Email,
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects renderer privilege remote filesystem and traversal configuration', function () {
    $planner = new RenderCompilerPlanner(new CanonicalJsonHasher);
    $input = task0034SecurityInput();
    $renderer = new RendererIdentity('vsn-email-compiler', '1.2.3');
    $policy = new RendererExecutionPolicy;

    foreach ([
        ['api_key' => 'secret'],
        ['provider_payload' => ['mime' => 'raw']],
        ['network' => true],
        ['include_path' => 'templates'],
        ['command' => 'wkhtmltopdf'],
        ['stylesheet' => 'https://attacker.example/style.css'],
        ['template' => '/etc/passwd'],
        ['source' => 'file:///tmp/template.html'],
        ['source' => '//attacker.example/template'],
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
        ))->toThrow(InvalidArgumentException::class);
    }

    $safe = $planner->plan(
        input: $input,
        target: RenderTarget::EmailHtml,
        renderer: $renderer,
        executionPolicy: $policy,
        configuration: ['label' => 'release..notes', 'minify' => true],
    );

    expect($safe->artifactIdentity)->toMatch('/^[a-f0-9]{64}$/');
});

it('keeps renderer and preview execution deny by default and bounded', function () {
    $renderPlan = task0034SecurityRenderPlan();
    $previewPolicy = new PreviewIsolationPolicy(
        maxDurationMs: 1500,
        maxMemoryMb: 96,
        maxOutputBytes: 750000,
        maxFindings: 20,
    );
    $previewPlanner = new PreviewPlanner(new CanonicalJsonHasher);
    $preview = $previewPlanner->plan(
        $renderPlan,
        new PreviewViewport('mobile', 320, 640, 2),
        $previewPolicy,
        'task0034-security-v1',
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
        new PreviewViewport('mobile', 320, 640, 2),
        new PreviewIsolationPolicy(maxDurationMs: 2500),
        'task0034-security-v1',
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer duration limit');

    expect(fn () => $previewPlanner->plan(
        $renderPlan,
        new PreviewViewport('mobile', 320, 640, 2),
        new PreviewIsolationPolicy(maxMemoryMb: 256),
        'task0034-security-v1',
    ))->toThrow(InvalidArgumentException::class, 'cannot exceed the pinned renderer memory limit');
});

it('certifies accessibility evidence and rejects inaccessible image authoring', function () {
    $sanitizer = new MarkupSanitizer;

    expect(fn () => $sanitizer->sanitize(
        '<img data-vsn-asset-ref="asset-v1">',
        AuthoringTarget::Email,
    ))->toThrow(InvalidArgumentException::class, 'require alt text');

    $decorative = $sanitizer->sanitize(
        '<img role="presentation" alt="" data-vsn-asset-ref="asset-v1" width="320">',
        AuthoringTarget::Email,
    );

    $hasher = new CanonicalJsonHasher;
    $preview = (new PreviewPlanner($hasher))->plan(
        task0034SecurityRenderPlan(),
        new PreviewViewport('reflow-320', 320, 720),
        new PreviewIsolationPolicy(maxOutputBytes: 1000000, maxFindings: 20),
        'task0034-a11y-v1',
    );

    $findings = [
        new PreviewFinding(
            'accessibility.alt_text_present',
            'info',
            'Meaningful images expose deterministic alternative text evidence.',
            'root/image',
        ),
        new PreviewFinding(
            'accessibility.contrast_review',
            'warning',
            'Representative text contrast requires review.',
            'root/section/text',
        ),
        new PreviewFinding(
            'accessibility.reflow_320',
            'info',
            'Representative 320 pixel reflow check completed.',
            'root/section',
        ),
    ];

    $first = PreviewRegressionResult::fromFindings($preview, $findings, $hasher);
    $reordered = PreviewRegressionResult::fromFindings(
        $preview,
        [$findings[2], $findings[0], $findings[1]],
        $hasher,
    );

    expect($decorative->markup)->toContain('role="presentation"')
        ->and($first->status)->toBe('review')
        ->and($first->resultIdentity)->toBe($reordered->resultIdentity)
        ->and($first->provenance()['findings'])->toBe($reordered->provenance()['findings']);
});

it('keeps derivative provenance free of credentials provider payloads and raw rendered output', function () {
    $hasher = new CanonicalJsonHasher;
    $render = task0034SecurityRenderPlan(['minify' => true, 'doctype' => 'html5']);
    $preview = (new PreviewPlanner($hasher))->plan(
        $render,
        new PreviewViewport('desktop', 1280, 720),
        new PreviewIsolationPolicy(maxOutputBytes: 1000000),
        'task0034-security-v1',
    );
    $result = PreviewRegressionResult::fromFindings(
        $preview,
        [new PreviewFinding('accessibility.structure', 'info', 'Semantic structure check completed.')],
        $hasher,
    );

    $encoded = json_encode([
        'render' => $render->provenance(),
        'preview' => $preview->provenance(),
        'result' => $result->provenance(),
    ], JSON_THROW_ON_ERROR);

    expect($render->provenance()['authoritative_source'])->toBeFalse()
        ->and($preview->provenance()['authoritative_source'])->toBeFalse()
        ->and($result->provenance()['authoritative_source'])->toBeFalse()
        ->and($encoded)->not->toContain('provider_payload')
        ->and($encoded)->not->toContain('api_key')
        ->and($encoded)->not->toContain('credential')
        ->and($encoded)->not->toContain('raw_output')
        ->and($encoded)->not->toContain('<html');
});
