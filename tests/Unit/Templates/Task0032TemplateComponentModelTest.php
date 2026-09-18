<?php

use App\Modules\Templates\Domain\ComponentLifecycle;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyGraph;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\ReusableComponent;
use App\Modules\Templates\Domain\Template;
use App\Modules\Templates\Domain\TemplateLifecycle;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;

it('keeps template and reusable component identities workspace scoped', function () {
    $template = new Template(
        id: 'template-1',
        workspaceId: 'workspace-1',
        name: 'Welcome email',
        lifecycle: TemplateLifecycle::Draft,
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );

    $component = new ReusableComponent(
        id: 'component-1',
        workspaceId: 'workspace-1',
        name: 'Primary CTA',
        lifecycle: ComponentLifecycle::Draft,
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );

    $activeTemplate = $template->transitionTo(TemplateLifecycle::Active, new DateTimeImmutable('2026-09-19T00:01:00+00:00'));
    $activeComponent = $component->transitionTo(ComponentLifecycle::Active, new DateTimeImmutable('2026-09-19T00:01:00+00:00'));

    expect($activeTemplate->id)->toBe('template-1')
        ->and($activeTemplate->workspaceId)->toBe('workspace-1')
        ->and($activeTemplate->lifecycle)->toBe(TemplateLifecycle::Active)
        ->and($activeComponent->id)->toBe('component-1')
        ->and($activeComponent->workspaceId)->toBe('workspace-1')
        ->and($activeComponent->lifecycle)->toBe(ComponentLifecycle::Active);
});

it('models immutable explicit version lineage and version-aware dependencies', function () {
    $component = new VersionedDefinition(
        id: 'component-v1',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Component,
        ownerId: 'component-1',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Published,
        dependencies: [],
        idempotencyKey: 'component-1-v1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );

    $template = new VersionedDefinition(
        id: 'template-v1',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Template,
        ownerId: 'template-1',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::ExecutionPinned,
        dependencies: [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
        idempotencyKey: 'template-1-v1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );

    $next = $template->fork(
        id: 'template-v2',
        dependencies: [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
        idempotencyKey: 'template-1-v2',
        createdByActorId: 'user-2',
        createdAt: new DateTimeImmutable('2026-09-19T00:02:00+00:00'),
    );

    expect($template->status->isImmutable())->toBeTrue()
        ->and($component->kind)->toBe(DefinitionKind::Component)
        ->and($next->kind)->toBe(DefinitionKind::Template)
        ->and($next->parentVersionId)->toBe('template-v1')
        ->and($next->versionNumber)->toBe(2)
        ->and($next->dependencies[0]->versionId)->toBe('component-v1');
});

it('rejects cross-workspace self and duplicate dependencies', function () {
    expect(fn () => new VersionedDefinition(
        id: 'template-v1',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Template,
        ownerId: 'template-1',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [new DependencyReference('workspace-2', DependencyKind::ComponentVersion, 'component-v1')],
        idempotencyKey: 'template-v1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Cross-workspace');

    expect(fn () => new VersionedDefinition(
        id: 'template-v1',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Template,
        ownerId: 'template-1',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [new DependencyReference('workspace-1', DependencyKind::TemplateVersion, 'template-v1')],
        idempotencyKey: 'template-v1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot depend on itself');

    $dependency = new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1');
    expect(fn () => new VersionedDefinition(
        id: 'template-v1',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Template,
        ownerId: 'template-1',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [$dependency, $dependency],
        idempotencyKey: 'template-v1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Duplicate version dependency');
});

it('rejects dependency cycles among known exact versions', function () {
    $a = new VersionedDefinition(
        id: 'a',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Component,
        ownerId: 'owner-a',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'b')],
        idempotencyKey: 'a-1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );
    $b = new VersionedDefinition(
        id: 'b',
        workspaceId: 'workspace-1',
        kind: DefinitionKind::Component,
        ownerId: 'owner-b',
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: VersionStatus::Draft,
        dependencies: [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'a')],
        idempotencyKey: 'b-1',
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );

    expect(fn () => new DependencyGraph([$a, $b]))
        ->toThrow(InvalidArgumentException::class, 'dependency cycle detected');
});
