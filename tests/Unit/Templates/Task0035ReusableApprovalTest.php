<?php

use App\Modules\Templates\Application\Governance\ReusableComponentGovernanceService;
use App\Modules\Templates\Domain\ComponentLifecycle;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\Governance\ReusableApprovalStatus;
use App\Modules\Templates\Domain\Governance\ReusableComponentScope;
use App\Modules\Templates\Domain\ReusableComponent;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;
use DateTimeImmutable;
use InvalidArgumentException;

function task0035ReusableComponent(
    string $id = 'component-1',
    string $workspaceId = 'workspace-1',
    ComponentLifecycle $lifecycle = ComponentLifecycle::Active,
): ReusableComponent {
    return new ReusableComponent(
        id: $id,
        workspaceId: $workspaceId,
        name: 'Reusable '.$id,
        lifecycle: $lifecycle,
        createdByActorId: 'component-admin',
        createdAt: new DateTimeImmutable('2026-09-20T12:30:00+00:00'),
    );
}

/** @param list<DependencyReference> $dependencies */
function task0035ReusableVersion(
    string $id = 'component-v1',
    string $componentId = 'component-1',
    string $workspaceId = 'workspace-1',
    VersionStatus $status = VersionStatus::Approved,
    array $dependencies = [],
): VersionedDefinition {
    return new VersionedDefinition(
        id: $id,
        workspaceId: $workspaceId,
        kind: DefinitionKind::Component,
        ownerId: $componentId,
        parentVersionId: null,
        versionNumber: 1,
        schemaVersion: 1,
        status: $status,
        dependencies: $dependencies,
        idempotencyKey: 'idem-'.$id,
        createdByActorId: 'component-admin',
        createdAt: new DateTimeImmutable('2026-09-20T12:30:00+00:00'),
    );
}

/** @param list<DependencyReference> $dependencies */
function task0035DependentVersion(
    string $id,
    DefinitionKind $kind,
    array $dependencies,
    string $workspaceId = 'workspace-1',
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
        createdByActorId: 'component-admin',
        createdAt: new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );
}

it('binds explicit reusable scope to an exact component version', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();

    $local = $service->initialize(
        component: $component,
        version: $version,
        scope: ReusableComponentScope::Local,
        actorId: 'component-admin',
        auditProvenance: ['source' => 'editor'],
        at: new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );
    $global = $service->initialize(
        component: $component,
        version: $version,
        scope: ReusableComponentScope::Global,
        actorId: 'component-admin',
        auditProvenance: ['source' => 'library'],
        at: new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );

    expect($local->scope)->toBe(ReusableComponentScope::Local)
        ->and($global->scope)->toBe(ReusableComponentScope::Global)
        ->and($global->componentVersionId)->toBe('component-v1')
        ->and($global->status())->toBe(ReusableApprovalStatus::Draft)
        ->and($global->events[0]->actorId)->toBe('component-admin')
        ->and($global->toArray()['events'][0]['audit_provenance'])->toBe(['source' => 'library']);
});

it('records deterministic PHASE-06 readiness and approval transitions with actor provenance', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();
    $initial = $service->initialize(
        $component,
        $version,
        ReusableComponentScope::Global,
        'author-1',
        ['source' => 'component-library'],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );

    $ready = $service->transition(
        $initial,
        $component,
        $version,
        ReusableApprovalStatus::Ready,
        'reviewer-1',
        ['reason' => 'content-ready'],
        new DateTimeImmutable('2026-09-20T12:32:00+00:00'),
    );
    $approved = $service->transition(
        $ready,
        $component,
        $version,
        ReusableApprovalStatus::Approved,
        'approver-1',
        ['reason' => 'shared-library-approved'],
        new DateTimeImmutable('2026-09-20T12:33:00+00:00'),
    );

    expect($initial->status())->toBe(ReusableApprovalStatus::Draft)
        ->and($approved->status())->toBe(ReusableApprovalStatus::Approved)
        ->and($approved->events)->toHaveCount(3)
        ->and($approved->events[1]->fromStatus)->toBe(ReusableApprovalStatus::Draft)
        ->and($approved->events[1]->toStatus)->toBe(ReusableApprovalStatus::Ready)
        ->and($approved->events[2]->actorId)->toBe('approver-1')
        ->and($approved->toArray()['status'])->toBe('approved');

    expect($service->transition(
        $approved,
        $component,
        $version,
        ReusableApprovalStatus::Approved,
        'approver-2',
        ['reason' => 'idempotent-replay'],
        new DateTimeImmutable('2026-09-20T12:34:00+00:00'),
    ))->toBe($approved);
});

it('requires active components and immutable exact versions before approval', function () {
    $service = new ReusableComponentGovernanceService;
    $inactive = task0035ReusableComponent(lifecycle: ComponentLifecycle::Draft);
    $immutable = task0035ReusableVersion();
    $inactiveGovernance = $service->initialize(
        $inactive,
        $immutable,
        ReusableComponentScope::Global,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );

    expect(fn () => $service->transition(
        $inactiveGovernance,
        $inactive,
        $immutable,
        ReusableApprovalStatus::Ready,
        'reviewer-1',
        [],
        new DateTimeImmutable('2026-09-20T12:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must be active');

    $active = task0035ReusableComponent();
    $draftVersion = task0035ReusableVersion(status: VersionStatus::Draft);
    $governance = $service->initialize(
        $active,
        $draftVersion,
        ReusableComponentScope::Global,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );
    $ready = $service->transition(
        $governance,
        $active,
        $draftVersion,
        ReusableApprovalStatus::Ready,
        'reviewer-1',
        [],
        new DateTimeImmutable('2026-09-20T12:32:00+00:00'),
    );

    expect(fn () => $service->transition(
        $ready,
        $active,
        $draftVersion,
        ReusableApprovalStatus::Approved,
        'approver-1',
        [],
        new DateTimeImmutable('2026-09-20T12:33:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'requires an immutable exact component version');
});

it('exposes deterministic direct and transitive dependency impact for exact reusable versions', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();
    $governance = $service->initialize(
        $component,
        $version,
        ReusableComponentScope::Global,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );

    $template = task0035DependentVersion(
        'template-v1',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );
    $nested = task0035DependentVersion(
        'component-v2',
        DefinitionKind::Component,
        [new DependencyReference('workspace-1', DependencyKind::TemplateVersion, 'template-v1')],
    );

    $impact = $service->analyzeImpact(
        $governance,
        $component,
        $version,
        [$nested, $version, $template],
    );

    expect($impact->impactedVersionIds)->toBe(['component-v2', 'template-v1'])
        ->and($impact->scope)->toBe(ReusableComponentScope::Global)
        ->and($impact->requiresNewVersionForEdit)->toBeTrue()
        ->and($impact->toArray()['component_version_id'])->toBe('component-v1');
});

it('forks approved or shared component edits without mutating pinned dependents', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();
    $governance = $service->initialize(
        $component,
        $version,
        ReusableComponentScope::Global,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );
    $ready = $service->transition(
        $governance,
        $component,
        $version,
        ReusableApprovalStatus::Ready,
        'reviewer-1',
        [],
        new DateTimeImmutable('2026-09-20T12:32:00+00:00'),
    );
    $approved = $service->transition(
        $ready,
        $component,
        $version,
        ReusableApprovalStatus::Approved,
        'approver-1',
        [],
        new DateTimeImmutable('2026-09-20T12:33:00+00:00'),
    );
    $template = task0035DependentVersion(
        'template-v1',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );

    $next = $service->forkForEdit(
        governance: $approved,
        component: $component,
        version: $version,
        newVersionId: 'component-v2',
        dependencies: [],
        idempotencyKey: 'idem-component-v2',
        actorId: 'editor-2',
        createdAt: new DateTimeImmutable('2026-09-20T12:34:00+00:00'),
    );

    expect($version->id)->toBe('component-v1')
        ->and($version->status)->toBe(VersionStatus::Approved)
        ->and($next->id)->toBe('component-v2')
        ->and($next->parentVersionId)->toBe('component-v1')
        ->and($next->versionNumber)->toBe(2)
        ->and($next->status)->toBe(VersionStatus::Draft)
        ->and($template->dependencies[0]->versionId)->toBe('component-v1');

    expect(fn () => $service->forkForEdit(
        $approved,
        $component,
        $version,
        'component-v1',
        [],
        'idem-same-version',
        'editor-2',
        new DateTimeImmutable('2026-09-20T12:34:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must create a new exact component version id');
});

it('fails closed on mismatched exact versions workspaces and non-component definitions', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();
    $governance = $service->initialize(
        $component,
        $version,
        ReusableComponentScope::Local,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );

    $otherVersion = task0035ReusableVersion(id: 'component-v2');
    expect(fn () => $service->analyzeImpact($governance, $component, $otherVersion, [$version, $otherVersion]))
        ->toThrow(InvalidArgumentException::class, 'not bound to the supplied exact component version');

    $foreignVersion = task0035ReusableVersion(workspaceId: 'workspace-2');
    expect(fn () => $service->initialize(
        $component,
        $foreignVersion,
        ReusableComponentScope::Local,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot cross workspaces');

    $template = task0035DependentVersion('template-v1', DefinitionKind::Template, []);
    expect(fn () => $service->initialize(
        $component,
        $template,
        ReusableComponentScope::Local,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'requires a component version');
});

it('rejects secret provider and PHASE-07 publication data from reusable governance audit provenance', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();

    foreach ([
        ['authorization_token' => 'secret'],
        ['provider_payload' => ['template' => 'remote']],
        ['campaign_id' => 'campaign-1'],
        ['schedule' => 'tomorrow'],
        ['publishing_state' => 'send-now'],
    ] as $auditProvenance) {
        expect(fn () => $service->initialize(
            $component,
            $version,
            ReusableComponentScope::Global,
            'author-1',
            $auditProvenance,
            new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
        ))->toThrow(InvalidArgumentException::class, 'Sensitive or PHASE-07 governance audit key is forbidden');
    }
});

it('rejects invalid approval skips backward audit time and retired edits', function () {
    $service = new ReusableComponentGovernanceService;
    $component = task0035ReusableComponent();
    $version = task0035ReusableVersion();
    $governance = $service->initialize(
        $component,
        $version,
        ReusableComponentScope::Global,
        'author-1',
        [],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );

    expect(fn () => $service->transition(
        $governance,
        $component,
        $version,
        ReusableApprovalStatus::Approved,
        'approver-1',
        [],
        new DateTimeImmutable('2026-09-20T12:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Invalid reusable governance transition');

    $ready = $service->transition(
        $governance,
        $component,
        $version,
        ReusableApprovalStatus::Ready,
        'reviewer-1',
        [],
        new DateTimeImmutable('2026-09-20T12:32:00+00:00'),
    );

    expect(fn () => $service->transition(
        $ready,
        $component,
        $version,
        ReusableApprovalStatus::Draft,
        'reviewer-1',
        [],
        new DateTimeImmutable('2026-09-20T12:30:59+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'time must not move backwards');

    $approved = $service->transition(
        $ready,
        $component,
        $version,
        ReusableApprovalStatus::Approved,
        'approver-1',
        [],
        new DateTimeImmutable('2026-09-20T12:33:00+00:00'),
    );
    $retired = $service->transition(
        $approved,
        $component,
        $version,
        ReusableApprovalStatus::Retired,
        'approver-1',
        ['reason' => 'superseded'],
        new DateTimeImmutable('2026-09-20T12:34:00+00:00'),
    );

    expect(fn () => $service->forkForEdit(
        $retired,
        $component,
        $version,
        'component-v2',
        [],
        'idem-component-v2',
        'editor-2',
        new DateTimeImmutable('2026-09-20T12:35:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot be edited');
});
