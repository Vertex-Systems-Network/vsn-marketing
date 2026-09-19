<?php

use App\Modules\Content\Application\Sanitization\MarkupSanitizer;
use App\Modules\Content\Domain\Authoring\AuthoringMode;
use App\Modules\Content\Domain\Authoring\AuthoringTarget;
use App\Modules\Content\Domain\Authoring\CanonicalAuthoringDraft;
use App\Modules\Content\Domain\Document\CanonicalNodeType;
use App\Modules\Content\Domain\Document\ContentNode;
use App\Modules\Content\Domain\Document\ContentTree;

function task0034AuthoringTree(): ContentTree
{
    return new ContentTree(new ContentNode(
        nodeId: 'root',
        type: CanonicalNodeType::Root,
        children: [
            new ContentNode(
                nodeId: 'section',
                type: CanonicalNodeType::Section,
                children: [
                    new ContentNode(
                        nodeId: 'text',
                        type: CanonicalNodeType::Text,
                        properties: ['text' => 'Canonical authoring output'],
                    ),
                ],
            ),
        ],
    ));
}

it('converges visual and safe markup authoring onto the same canonical tree', function () {
    $tree = task0034AuthoringTree();
    $sanitizer = new MarkupSanitizer;
    $sanitized = $sanitizer->sanitize('<p>Imported content</p>', AuthoringTarget::Email);

    $visual = new CanonicalAuthoringDraft(
        workspaceId: 'workspace-1',
        documentId: 'content-1',
        baseVersionId: 'version-1',
        createdByActorId: 'user-1',
        mode: AuthoringMode::Visual,
        tree: $tree,
        sourceFingerprint: hash('sha256', 'visual-edit-command'),
    );

    $imported = new CanonicalAuthoringDraft(
        workspaceId: 'workspace-1',
        documentId: 'content-1',
        baseVersionId: 'version-1',
        createdByActorId: 'user-1',
        mode: AuthoringMode::SafeMarkupImport,
        tree: $tree,
        sourceFingerprint: hash('sha256', '<p>Imported content</p>'),
        markupTarget: $sanitized->target,
        sanitizedMarkupHash: $sanitized->sha256,
    );

    expect($visual->tree)->toBe($imported->tree)
        ->and($visual->provenance()['sanitized_markup_sha256'])->toBeNull()
        ->and($imported->provenance())->toMatchArray([
            'authoring_mode' => 'safe_markup_import',
            'base_version_id' => 'version-1',
            'markup_target' => 'email',
            'sanitized_markup_sha256' => $sanitized->sha256,
        ])
        ->and(json_encode($imported->provenance(), JSON_THROW_ON_ERROR))->not->toContain('Imported content');
});

it('requires sanitizer evidence only for the safe markup path', function () {
    $tree = task0034AuthoringTree();

    expect(fn () => new CanonicalAuthoringDraft(
        workspaceId: 'workspace-1',
        documentId: 'content-1',
        baseVersionId: 'version-1',
        createdByActorId: 'user-1',
        mode: AuthoringMode::SafeMarkupImport,
        tree: $tree,
        sourceFingerprint: hash('sha256', 'unsafe import'),
    ))->toThrow(InvalidArgumentException::class, 'requires target and sanitizer evidence');

    expect(fn () => new CanonicalAuthoringDraft(
        workspaceId: 'workspace-1',
        documentId: 'content-1',
        baseVersionId: 'version-1',
        createdByActorId: 'user-1',
        mode: AuthoringMode::Visual,
        tree: $tree,
        sourceFingerprint: hash('sha256', 'visual'),
        markupTarget: AuthoringTarget::Email,
        sanitizedMarkupHash: hash('sha256', 'sanitized'),
    ))->toThrow(InvalidArgumentException::class, 'cannot attach sanitizer evidence');
});

it('canonicalizes allowed markup deterministically and idempotently', function () {
    $sanitizer = new MarkupSanitizer;

    $first = $sanitizer->sanitize(
        '<p style="font-weight: bold; color: #FF0000" class="hero">Hello <strong>World</strong></p>',
        AuthoringTarget::Email,
    );
    $second = $sanitizer->sanitize(
        '<p class="hero" style="color:#ff0000;font-weight:bold">Hello <strong>World</strong></p>',
        AuthoringTarget::Email,
    );
    $replayed = $sanitizer->sanitize($first->markup, AuthoringTarget::Email);

    expect($first->markup)->toBe('<p class="hero" style="color:#ff0000;font-weight:bold">Hello <strong>World</strong></p>')
        ->and($second->markup)->toBe($first->markup)
        ->and($second->sha256)->toBe($first->sha256)
        ->and($replayed->markup)->toBe($first->markup)
        ->and($replayed->sha256)->toBe($first->sha256)
        ->and($first->evidence())->toMatchArray([
            'target' => 'email',
            'policy_version' => 'vsn-html-policy-v1',
            'sha256' => $first->sha256,
        ]);
});

it('enforces target-specific element policy', function () {
    $sanitizer = new MarkupSanitizer;

    $web = $sanitizer->sanitize('<article><p>Safe</p></article>', AuthoringTarget::Web);

    expect($web->markup)->toBe('<article><p>Safe</p></article>');

    expect(fn () => $sanitizer->sanitize(
        '<article><p>Not valid email authoring</p></article>',
        AuthoringTarget::Email,
    ))->toThrow(InvalidArgumentException::class, 'Unsupported authored markup element for email: article');
});

it('rejects executable markup event handlers unsafe urls and remote media fetches', function () {
    $sanitizer = new MarkupSanitizer;

    expect(fn () => $sanitizer->sanitize(
        '<script>alert(1)</script>',
        AuthoringTarget::Web,
    ))->toThrow(InvalidArgumentException::class, 'Executable or active authored markup element is forbidden: script');

    expect(fn () => $sanitizer->sanitize(
        '<p onclick="alert(1)">Click</p>',
        AuthoringTarget::Web,
    ))->toThrow(InvalidArgumentException::class, 'event handler attribute is forbidden');

    expect(fn () => $sanitizer->sanitize(
        '<a href="java script:alert(1)">Unsafe</a>',
        AuthoringTarget::Web,
    ))->toThrow(InvalidArgumentException::class, 'unsupported or unsafe URL scheme');

    expect(fn () => $sanitizer->sanitize(
        '<img src="https://example.com/tracker.png" alt="tracker">',
        AuthoringTarget::Email,
    ))->toThrow(InvalidArgumentException::class, 'Unsupported authored markup attribute on img: src');
});

it('rejects active css constructs and enforces canonical asset accessibility references', function () {
    $sanitizer = new MarkupSanitizer;

    expect(fn () => $sanitizer->sanitize(
        '<p style="background-image:url(https://example.com/tracker.png)">Unsafe</p>',
        AuthoringTarget::Email,
    ))->toThrow(InvalidArgumentException::class, 'forbidden CSS construct');

    expect(fn () => $sanitizer->sanitize(
        '<img data-vsn-asset-ref="asset-version-1">',
        AuthoringTarget::Email,
    ))->toThrow(InvalidArgumentException::class, 'require alt text');

    expect(fn () => $sanitizer->sanitize(
        '<img data-vsn-asset-ref="asset-version-1" alt="">',
        AuthoringTarget::Email,
    ))->toThrow(InvalidArgumentException::class, 'Empty image alt text requires decorative semantics');

    $decorative = $sanitizer->sanitize(
        '<img role="presentation" alt="" data-vsn-asset-ref="asset-version-1" width="640">',
        AuthoringTarget::Email,
    );

    expect($decorative->markup)
        ->toBe('<img alt="" data-vsn-asset-ref="asset-version-1" role="presentation" width="640">');
});

it('rejects parser declarations unsupported attributes and invalid authoring fingerprints', function () {
    $sanitizer = new MarkupSanitizer;

    expect(fn () => $sanitizer->sanitize(
        '<!DOCTYPE html><p>Unsafe declaration</p>',
        AuthoringTarget::Web,
    ))->toThrow(InvalidArgumentException::class, 'cannot declare document types');

    expect(fn () => $sanitizer->sanitize(
        '<p data-provider-payload="unsafe">Content</p>',
        AuthoringTarget::Web,
    ))->toThrow(InvalidArgumentException::class, 'Unsupported authored markup attribute');

    expect(fn () => new CanonicalAuthoringDraft(
        workspaceId: 'workspace-1',
        documentId: 'content-1',
        baseVersionId: 'version-1',
        createdByActorId: 'user-1',
        mode: AuthoringMode::Visual,
        tree: task0034AuthoringTree(),
        sourceFingerprint: 'not-a-hash',
    ))->toThrow(InvalidArgumentException::class, 'must be a SHA-256 hex digest');
});
