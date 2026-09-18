<?php

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Application\DependencyResolution\DependencyImpactAnalyzer;
use App\Modules\Content\Application\DependencyResolution\DependencyResolver;
use App\Modules\Content\Domain\Binding\BindingResolver;
use App\Modules\Content\Domain\Binding\LocalizationSchema;
use App\Modules\Content\Domain\Binding\ResolvedBindings;
use App\Modules\Content\Domain\Binding\VariableDefinition;
use App\Modules\Content\Domain\Binding\VariableSchema;
use App\Modules\Content\Domain\Binding\VariableType;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\DependencyKind;
use App\Modules\Templates\Domain\DependencyReference;
use App\Modules\Templates\Domain\VersionedDefinition;
use App\Modules\Templates\Domain\VersionStatus;

function task0032BindingDefinition(
    string $id,
    DefinitionKind $kind,
    array $dependencies = [],
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
        createdByActorId: 'user-1',
        createdAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00'),
    );
}

it('binds typed variables and localization through deterministic fallback', function () {
    $variables = new VariableSchema([
        new VariableDefinition('count', VariableType::Integer, required: false, hasDefault: true, defaultValue: 0),
        new VariableDefinition('first_name', VariableType::String),
    ]);
    $localization = new LocalizationSchema(
        defaultLocale: 'en',
        slots: new VariableSchema([
            new VariableDefinition('preheader', VariableType::String),
            new VariableDefinition('subject', VariableType::String),
        ]),
        allowedLocales: ['en', 'fr', 'fr-CA'],
        fallbacks: ['fr-CA' => ['fr']],
    );

    $resolved = (new BindingResolver)->resolve(
        variableSchema: $variables,
        variables: ['first_name' => 'Ada'],
        localizationSchema: $localization,
        requestedLocale: 'fr-CA',
        translations: [
            'fr' => ['subject' => 'Bonjour'],
            'en' => ['subject' => 'Hello', 'preheader' => 'Preview'],
        ],
    );

    expect($resolved->variables)->toBe(['count' => 0, 'first_name' => 'Ada'])
        ->and($resolved->localized)->toBe(['preheader' => 'Preview', 'subject' => 'Bonjour'])
        ->and($resolved->localeChain)->toBe(['fr-CA', 'fr', 'en']);
});

it('fails closed on missing incompatible or undeclared binding inputs', function () {
    $schema = new VariableSchema([
        new VariableDefinition('count', VariableType::Integer),
    ]);

    expect(fn () => $schema->bind([]))
        ->toThrow(InvalidArgumentException::class, 'Required variable is unresolved');

    expect(fn () => $schema->bind(['count' => '1']))
        ->toThrow(InvalidArgumentException::class, 'does not match integer');

    expect(fn () => $schema->bind(['count' => 1, 'callback' => 'run']))
        ->toThrow(InvalidArgumentException::class, 'Unknown variable input');
});

it('canonicalizes associative input deterministically while preserving list order', function () {
    $hasher = new CanonicalJsonHasher;

    $left = ['z' => 1, 'nested' => ['b' => 2, 'a' => 1], 'items' => ['a', 'b']];
    $right = ['items' => ['a', 'b'], 'nested' => ['a' => 1, 'b' => 2], 'z' => 1];

    expect($hasher->hash($left))->toBe($hasher->hash($right))
        ->and($hasher->hash(['items' => ['a', 'b']]))->not->toBe($hasher->hash(['items' => ['b', 'a']]));

    expect(fn () => $hasher->hash(['unsafe' => new stdClass]))
        ->toThrow(InvalidArgumentException::class, 'cannot contain executable objects or resources');
});

it('resolves exact dependency versions in deterministic topological order', function () {
    $componentOne = task0032BindingDefinition('component-v1', DefinitionKind::Component);
    $templateOne = task0032BindingDefinition(
        'template-v1',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );
    $componentTwo = task0032BindingDefinition(
        'component-v2',
        DefinitionKind::Component,
        [new DependencyReference('workspace-1', DependencyKind::TemplateVersion, 'template-v1')],
    );
    $root = task0032BindingDefinition(
        'template-root',
        DefinitionKind::Template,
        [
            new DependencyReference('workspace-1', DependencyKind::TemplateVersion, 'template-v1'),
            new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v2'),
        ],
    );

    $resolved = (new DependencyResolver)->resolve(
        $root,
        [$componentTwo, $templateOne, $componentOne, $root],
    );

    expect($resolved->versionIds())->toBe(['component-v1', 'template-v1', 'component-v2']);
});

it('rejects missing mismatched and cyclic exact dependencies', function () {
    $missing = task0032BindingDefinition(
        'root-missing',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'missing-v1')],
    );

    expect(fn () => (new DependencyResolver)->resolve($missing, []))
        ->toThrow(InvalidArgumentException::class, 'Exact dependency version is unavailable');

    $template = task0032BindingDefinition('template-v1', DefinitionKind::Template);
    $mismatched = task0032BindingDefinition(
        'root-mismatch',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'template-v1')],
    );

    expect(fn () => (new DependencyResolver)->resolve($mismatched, [$template]))
        ->toThrow(InvalidArgumentException::class, 'kind does not match');

    $a = task0032BindingDefinition(
        'a',
        DefinitionKind::Component,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'b')],
    );
    $b = task0032BindingDefinition(
        'b',
        DefinitionKind::Component,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'a')],
    );

    expect(fn () => (new DependencyResolver)->resolve($a, [$a, $b]))
        ->toThrow(InvalidArgumentException::class, 'Dependency cycle detected');
});

it('exposes transitive dependency impact and stable render input identity', function () {
    $component = task0032BindingDefinition('component-v1', DefinitionKind::Component);
    $template = task0032BindingDefinition(
        'template-v1',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );
    $root = task0032BindingDefinition(
        'template-root',
        DefinitionKind::Template,
        [new DependencyReference('workspace-1', DependencyKind::TemplateVersion, 'template-v1')],
    );

    $impacted = (new DependencyImpactAnalyzer)->impactedDependents(
        'workspace-1',
        'component-v1',
        [$component, $root, $template],
    );

    expect($impacted)->toBe(['template-root', 'template-v1']);

    $bindings = new ResolvedBindings(
        variables: ['name' => 'Ada'],
        localized: ['subject' => 'Hello'],
        requestedLocale: 'en',
        localeChain: ['en'],
    );
    $hasher = new CanonicalJsonHasher;

    $first = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-root',
        dependencyVersionIds: ['template-v1', 'component-v1'],
        bindings: $bindings,
        assetReferences: ['asset-b', 'asset-a'],
        brandReferences: ['brand-v1'],
    );
    $equivalent = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v1',
        templateVersionId: 'template-root',
        dependencyVersionIds: ['component-v1', 'template-v1'],
        bindings: $bindings,
        assetReferences: ['asset-a', 'asset-b'],
        brandReferences: ['brand-v1'],
    );
    $changed = new RenderInputSnapshot(
        workspaceId: 'workspace-1',
        contentVersionId: 'content-v2',
        templateVersionId: 'template-root',
        dependencyVersionIds: ['component-v1', 'template-v1'],
        bindings: $bindings,
    );

    expect($first->identity($hasher))->toBe($equivalent->identity($hasher))
        ->and($changed->identity($hasher))->not->toBe($first->identity($hasher));
});
