<?php

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Domain\Document\CanonicalNodeType;
use App\Modules\Content\Domain\Document\ContentDocument;
use App\Modules\Content\Domain\Document\ContentLifecycle;
use App\Modules\Content\Domain\Document\ContentNode;
use App\Modules\Content\Domain\Document\ContentTree;
use App\Modules\Content\Domain\Version\ContentVersion;
use App\Modules\Content\Domain\Version\ContentVersionStatus;
use App\Modules\Content\Infrastructure\Persistence\DatabaseContentRepository;
use App\Modules\Templates\Domain\ComponentLifecycle;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\ReusableComponent;
use App\Modules\Templates\Domain\Template;
use App\Modules\Templates\Domain\TemplateLifecycle;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use App\Modules\Templates\Infrastructure\Persistence\DatabaseTemplateRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) === false) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0032 PostgreSQL persistence tests.');
    }
});

function task0032PersistenceWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0032 '.$suffix,
        'slug' => 'task0032-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0032 Workspace '.$suffix,
        'slug' => 'task0032-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

function task0032PersistenceTree(string $text): ContentTree
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

function task0032PersistenceDocument(string $workspaceId, string $id, DateTimeImmutable $at): ContentDocument
{
    return new ContentDocument(
        id: $id,
        workspaceId: $workspaceId,
        name: 'Canonical document',
        lifecycle: ContentLifecycle::Draft,
        createdByActorId: 'task0032-user',
        auditProvenance: ['source' => 'integration'],
        createdAt: $at,
    );
}

it('persists content identities and immutable versions with replay-safe canonical hashes on PostgreSQL', function () {
    $workspaceId = task0032PersistenceWorkspace('content');
    $repository = app(DatabaseContentRepository::class);
    $hasher = app(CanonicalJsonHasher::class);
    $at = new DateTimeImmutable('2026-09-19T01:00:00+00:00');
    $document = task0032PersistenceDocument($workspaceId, (string) Str::uuid(), $at);

    expect($repository->createDocument($document)->id)->toBe($document->id)
        ->and($repository->createDocument($document)->id)->toBe($document->id);

    $first = ContentVersion::initialFor(
        document: $document,
        id: (string) Str::uuid(),
        tree: task0032PersistenceTree('Version one'),
        createdByActorId: 'task0032-user',
        auditProvenance: ['source' => 'integration'],
        idempotencyKey: 'content-version-1',
        createdAt: $at,
        status: ContentVersionStatus::Published,
    );

    $repository->appendVersion($first);
    $replayed = $repository->appendVersion($first);
    $hydrated = $repository->findVersion($workspaceId, $first->id);

    expect($replayed->id)->toBe($first->id)
        ->and($hydrated)->not->toBeNull()
        ->and($hasher->hash($hydrated?->tree->toArray()))->toBe($hasher->hash($first->tree->toArray()))
        ->and(DB::table('content_versions')->where('idempotency_key', 'content-version-1')->count())->toBe(1);

    expect(fn () => DB::table('content_versions')->where('id', $first->id)->update(['status' => 'draft']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('content_versions')->where('id', $first->id)->delete())
        ->toThrow(QueryException::class);
});

it('enforces optimistic concurrency and exact content-version parent lineage on PostgreSQL', function () {
    $workspaceId = task0032PersistenceWorkspace('concurrency');
    $repository = app(DatabaseContentRepository::class);
    $createdAt = new DateTimeImmutable('2026-09-19T02:00:00+00:00');
    $document = task0032PersistenceDocument($workspaceId, (string) Str::uuid(), $createdAt);
    $repository->createDocument($document);

    $active = $document->transitionTo(ContentLifecycle::Active, new DateTimeImmutable('2026-09-19T02:01:00+00:00'));
    $repository->updateDocument($active, null);

    $stale = $document->transitionTo(ContentLifecycle::Archived, new DateTimeImmutable('2026-09-19T02:02:00+00:00'));
    expect(fn () => $repository->updateDocument($stale, null))
        ->toThrow(InvalidArgumentException::class, 'optimistic concurrency conflict');

    $first = ContentVersion::initialFor(
        document: $document,
        id: (string) Str::uuid(),
        tree: task0032PersistenceTree('v1'),
        createdByActorId: 'task0032-user',
        auditProvenance: [],
        idempotencyKey: 'lineage-v1',
        createdAt: $createdAt,
    );
    $repository->appendVersion($first);

    $invalid = new ContentVersion(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        documentId: $document->id,
        parentVersionId: $first->id,
        versionNumber: 3,
        schemaVersion: ContentVersion::SCHEMA_VERSION,
        status: ContentVersionStatus::Draft,
        tree: task0032PersistenceTree('v3'),
        createdByActorId: 'task0032-user',
        auditProvenance: [],
        idempotencyKey: 'lineage-v3',
        createdAt: new DateTimeImmutable('2026-09-19T02:03:00+00:00'),
    );

    expect(fn () => $repository->appendVersion($invalid))
        ->toThrow(InvalidArgumentException::class, 'advance exactly one version');
});

it('persists exact template and component versions and rejects unresolved dependencies on PostgreSQL', function () {
    $workspaceId = task0032PersistenceWorkspace('templates');
    $repository = app(DatabaseTemplateRepository::class);
    $at = new DateTimeImmutable('2026-09-19T03:00:00+00:00');
    $template = new Template(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'Transactional template',
        lifecycle: TemplateLifecycle::Draft,
        createdByActorId: 'task0032-user',
        createdAt: $at,
    );
    $component = new ReusableComponent(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        name: 'CTA component',
        lifecycle: ComponentLifecycle::Draft,
        createdByActorId: 'task0032-user',
        createdAt: $at,
    );

    $repository->createTemplate($template);
    $repository->createComponent($component);

    $componentVersion = new VersionedDefinition(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: DefinitionKind::Component,
        ownerId: $component->id,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Published,
        dependencies: [],
        idempotencyKey: 'component-v1',
        createdByActorId: 'task0032-user',
        createdAt: $at,
    );
    $repository->appendVersion(
        $componentVersion,
        definition: ['type' => 'button', 'properties' => ['label' => 'Buy']],
        variableSchema: ['label' => ['type' => 'string']],
    );

    $templateVersion = new VersionedDefinition(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: DefinitionKind::Template,
        ownerId: $template->id,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::ExecutionPinned,
        dependencies: [new DependencyReference($workspaceId, DependencyKind::ComponentVersion, $componentVersion->id)],
        idempotencyKey: 'template-v1',
        createdByActorId: 'task0032-user',
        createdAt: $at,
    );

    $repository->appendVersion(
        $templateVersion,
        definition: ['root' => ['component_version_id' => $componentVersion->id]],
        localizationSchema: ['default_locale' => 'en'],
    );
    $replay = $repository->appendVersion(
        $templateVersion,
        definition: ['root' => ['component_version_id' => $componentVersion->id]],
        localizationSchema: ['default_locale' => 'en'],
    );

    expect($replay->id)->toBe($templateVersion->id)
        ->and($repository->findVersion($workspaceId, DefinitionKind::Template, $templateVersion->id)?->dependencies[0]->versionId)
        ->toBe($componentVersion->id);

    $missing = new VersionedDefinition(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        kind: DefinitionKind::Template,
        ownerId: $template->id,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [new DependencyReference($workspaceId, DependencyKind::ComponentVersion, (string) Str::uuid())],
        idempotencyKey: 'template-missing-dependency',
        createdByActorId: 'task0032-user',
        createdAt: $at,
    );

    expect(fn () => $repository->appendVersion($missing, ['root' => []]))
        ->toThrow(InvalidArgumentException::class, 'dependency target does not exist');
});

it('fails closed across workspaces and detects a dependency cycle exposed through stored evidence on PostgreSQL', function () {
    $firstWorkspace = task0032PersistenceWorkspace('scope-a');
    $secondWorkspace = task0032PersistenceWorkspace('scope-b');
    $contentRepository = app(DatabaseContentRepository::class);
    $templateRepository = app(DatabaseTemplateRepository::class);
    $at = new DateTimeImmutable('2026-09-19T04:00:00+00:00');

    $foreignDocument = task0032PersistenceDocument($secondWorkspace, (string) Str::uuid(), $at);
    $contentRepository->createDocument($foreignDocument);

    expect(fn () => $contentRepository->findDocument($firstWorkspace, $foreignDocument->id))
        ->toThrow(AuthorizationException::class);

    $componentA = new ReusableComponent((string) Str::uuid(), $firstWorkspace, 'A', ComponentLifecycle::Draft, 'user', $at);
    $componentB = new ReusableComponent((string) Str::uuid(), $firstWorkspace, 'B', ComponentLifecycle::Draft, 'user', $at);
    $templateRepository->createComponent($componentA);
    $templateRepository->createComponent($componentB);

    $candidateId = (string) Str::uuid();
    $storedId = (string) Str::uuid();
    DB::table('reusable_component_versions')->insert([
        'id' => $storedId,
        'workspace_id' => $firstWorkspace,
        'component_id' => $componentB->id,
        'parent_version_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'status' => VersionStatus::Draft->value,
        'definition' => json_encode(['type' => 'stored'], JSON_THROW_ON_ERROR),
        'variable_schema' => '[]',
        'localization_schema' => '[]',
        'dependencies' => json_encode([[
            'workspace_id' => $firstWorkspace,
            'kind' => DependencyKind::ComponentVersion->value,
            'version_id' => $candidateId,
        ]], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'stored-cycle',
        'created_by_actor_id' => 'user',
        'created_at' => $at,
    ]);

    $candidate = new VersionedDefinition(
        id: $candidateId,
        workspaceId: $firstWorkspace,
        kind: DefinitionKind::Component,
        ownerId: $componentA->id,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [new DependencyReference($firstWorkspace, DependencyKind::ComponentVersion, $storedId)],
        idempotencyKey: 'candidate-cycle',
        createdByActorId: 'user',
        createdAt: $at,
    );

    expect(fn () => $templateRepository->appendVersion($candidate, ['type' => 'candidate']))
        ->toThrow(InvalidArgumentException::class, 'dependency cycle detected');
});
