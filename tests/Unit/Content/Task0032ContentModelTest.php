<?php

use App\Modules\Content\Domain\Document\CanonicalNodeType;
use App\Modules\Content\Domain\Document\ContentDocument;
use App\Modules\Content\Domain\Document\ContentLifecycle;
use App\Modules\Content\Domain\Document\ContentNode;
use App\Modules\Content\Domain\Document\ContentTree;
use App\Modules\Content\Domain\Version\ContentVersion;
use App\Modules\Content\Domain\Version\ContentVersionStatus;

function task0032ContentTree(string $text = 'Hello world'): ContentTree
{
    return new ContentTree(new ContentNode(
        nodeId: 'root',
        type: CanonicalNodeType::Root,
        children: [
            new ContentNode(
                nodeId: 'section-1',
                type: CanonicalNodeType::Section,
                properties: ['layout' => ['columns' => 1]],
                children: [
                    new ContentNode(
                        nodeId: 'text-1',
                        type: CanonicalNodeType::Text,
                        properties: ['text' => $text, 'accessibility' => ['role' => 'paragraph']],
                    ),
                ],
            ),
        ],
    ));
}

function task0032Document(): ContentDocument
{
    return new ContentDocument(
        id: 'content-1',
        workspaceId: 'workspace-1',
        name: 'Welcome message',
        lifecycle: ContentLifecycle::Draft,
        createdByActorId: 'user-1',
        auditProvenance: ['source' => 'operator'],
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );
}

it('models a provider-neutral typed canonical content tree', function () {
    $tree = task0032ContentTree();

    expect($tree->schemaVersion)->toBe(ContentTree::SCHEMA_VERSION)
        ->and($tree->root->type)->toBe(CanonicalNodeType::Root)
        ->and($tree->toArray()['root']['children'][0]['children'][0]['properties']['text'])->toBe('Hello world');
});

it('rejects provider render payloads unsupported properties and executable urls', function () {
    expect(fn () => new ContentNode(
        nodeId: 'text-1',
        type: CanonicalNodeType::Text,
        properties: ['text' => 'hello', 'provider_payload' => ['html' => '<p>hello</p>']],
    ))->toThrow(InvalidArgumentException::class, 'Unsupported canonical text node property');

    expect(fn () => new ContentNode(
        nodeId: 'button-1',
        type: CanonicalNodeType::Button,
        properties: ['label' => 'Run', 'href' => 'javascript:alert(1)'],
    ))->toThrow(InvalidArgumentException::class, 'executable or data URL scheme');
});

it('requires deterministic unique node identities and supported schema versions', function () {
    expect(fn () => new ContentTree(new ContentNode(
        nodeId: 'root',
        type: CanonicalNodeType::Root,
        children: [
            new ContentNode(nodeId: 'duplicate', type: CanonicalNodeType::Text, properties: ['text' => 'a']),
            new ContentNode(nodeId: 'duplicate', type: CanonicalNodeType::Text, properties: ['text' => 'b']),
        ],
    )))->toThrow(InvalidArgumentException::class, 'Duplicate canonical content node id');

    expect(fn () => new ContentTree(
        root: new ContentNode(nodeId: 'root', type: CanonicalNodeType::Root),
        schemaVersion: 99,
    ))->toThrow(InvalidArgumentException::class, 'Unsupported canonical content tree schema version');
});

it('keeps stable document identity and lifecycle transitions monotonic', function () {
    $document = task0032Document();
    $active = $document->transitionTo(ContentLifecycle::Active, new DateTimeImmutable('2026-09-19T00:01:00+00:00'));
    $archived = $active->transitionTo(ContentLifecycle::Archived, new DateTimeImmutable('2026-09-19T00:02:00+00:00'));

    expect($archived->id)->toBe($document->id)
        ->and($archived->workspaceId)->toBe('workspace-1')
        ->and($archived->lifecycle)->toBe(ContentLifecycle::Archived);

    expect(fn () => $archived->transitionTo(ContentLifecycle::Active, new DateTimeImmutable('2026-09-19T00:03:00+00:00')))
        ->toThrow(InvalidArgumentException::class, 'Invalid content document lifecycle transition');
});

it('creates immutable version lineage instead of rewriting historical inputs', function () {
    $document = task0032Document();
    $first = ContentVersion::initialFor(
        document: $document,
        id: 'version-1',
        tree: task0032ContentTree('Version one'),
        createdByActorId: 'user-1',
        auditProvenance: ['reason' => 'initial'],
        idempotencyKey: 'content-version-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
        status: ContentVersionStatus::Published,
    );
    $second = $first->fork(
        id: 'version-2',
        tree: task0032ContentTree('Version two'),
        createdByActorId: 'user-2',
        auditProvenance: ['reason' => 'edit'],
        idempotencyKey: 'content-version-2',
        createdAt: new DateTimeImmutable('2026-09-19T00:05:00+00:00'),
    );

    expect($first->status->isExecutionImmutable())->toBeTrue()
        ->and($first->tree->toArray()['root']['children'][0]['children'][0]['properties']['text'])->toBe('Version one')
        ->and($second->parentVersionId)->toBe('version-1')
        ->and($second->versionNumber)->toBe(2)
        ->and($second->status)->toBe(ContentVersionStatus::Draft)
        ->and($second->tree->toArray()['root']['children'][0]['children'][0]['properties']['text'])->toBe('Version two');
});

it('rejects invalid lineage replay bounds and sensitive audit provenance', function () {
    expect(fn () => new ContentVersion(
        id: 'version-2',
        workspaceId: 'workspace-1',
        documentId: 'content-1',
        parentVersionId: null,
        versionNumber: 2,
        schemaVersion: ContentVersion::SCHEMA_VERSION,
        status: ContentVersionStatus::Draft,
        tree: task0032ContentTree(),
        createdByActorId: 'user-1',
        auditProvenance: [],
        idempotencyKey: 'version-2',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Only the first content version may omit parent lineage');

    expect(fn () => new ContentDocument(
        id: 'content-1',
        workspaceId: 'workspace-1',
        name: 'Unsafe',
        lifecycle: ContentLifecycle::Draft,
        createdByActorId: 'user-1',
        auditProvenance: ['nested' => ['api_key' => 'forbidden']],
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive content provenance key is forbidden');
});
