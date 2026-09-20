<?php

use App\Modules\Content\Application\Brand\BrandReferenceResolver;
use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Domain\Brand\BrandKit;
use App\Modules\Content\Domain\Brand\BrandReference;
use App\Modules\Content\Domain\Brand\BrandStyleToken;
use App\Modules\Content\Domain\Brand\BrandTokenKind;
use App\Modules\Content\Domain\Brand\BrandVersion;
use App\Modules\Content\Domain\Brand\BrandVersionStatus;
use App\Modules\Providers\Application\TemplateSync\ProviderTemplateSyncPlanner;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\Templates\ProviderTemplateDrift;
use App\Modules\Providers\Domain\Templates\ProviderTemplateMapping;
use App\Modules\Providers\Domain\Templates\ProviderTemplateObservation;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncAction;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncRequest;
use App\Modules\Providers\Infrastructure\TemplateSync\InMemoryProviderTemplateSyncLedger;
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

function task0035CertificationBrand(string $id, string $workspace = 'workspace-1', string $color = '#006039'): BrandVersion
{
    $kit = new BrandKit('brand-kit-1', $workspace, 'VSN', 'brand-admin', new DateTimeImmutable('2026-09-20T12:00:00+00:00'));

    return BrandVersion::initialFor(
        brandKit: $kit,
        id: $id,
        styleTokens: [
            new BrandStyleToken('color.primary', BrandTokenKind::Color, $color),
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
        ],
        identityMetadata: ['display_name' => 'VSN'],
        assetReferences: ['asset-logo-v1'],
        defaults: ['locale' => 'en'],
        createdByActorId: 'brand-admin',
        auditProvenance: ['source' => 'task0035-certification'],
        idempotencyKey: 'idem-'.$id,
        createdAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        status: BrandVersionStatus::Published,
    );
}

function task0035CertificationVersion(
    string $id,
    string $ownerId,
    array $dependencies = [],
    VersionStatus $status = VersionStatus::Approved,
    string $workspace = 'workspace-1',
): VersionedDefinition {
    return new VersionedDefinition(
        id: $id,
        workspaceId: $workspace,
        kind: DefinitionKind::Component,
        ownerId: $ownerId,
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

function task0035CertificationCapability(string $sourceVersion = '2026-09'): ProviderCapability
{
    return new ProviderCapability(
        id: 'capability-1',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        connectionId: 'connection-1',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'media_kinds' => ['image', 'text']],
        sourceUrl: 'https://example.test/provider-template-docs',
        sourceVersion: $sourceVersion,
        observedAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-20T12:00:00+00:00'),
    );
}

function task0035CertificationRequest(string $idempotencyKey = 'sync-1', string $version = 'template-v1'): ProviderTemplateSyncRequest
{
    return new ProviderTemplateSyncRequest(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        canonicalTemplateVersionId: $version,
        canonicalSourceIdentity: $version === 'template-v1' ? str_repeat('a', 64) : str_repeat('d', 64),
        componentVersionIds: ['component-v1'],
        brandVersionIds: ['brand-v1'],
        templateKind: 'email',
        mediaKinds: ['image', 'text'],
        idempotencyKey: $idempotencyKey,
    );
}

function task0035CertificationMapping(): ProviderTemplateMapping
{
    return new ProviderTemplateMapping(
        id: 'mapping-1',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        providerTemplateReference: 'provider-template-42',
        lastSyncedCanonicalVersionId: null,
        lastSyncedDerivativeIdentity: null,
        lastObservedProviderFingerprint: null,
        createdAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
    );
}

function task0035CertificationObservation(string $fingerprint = ''): ProviderTemplateObservation
{
    return new ProviderTemplateObservation(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        providerTemplateReference: 'provider-template-42',
        providerFingerprint: $fingerprint !== '' ? $fingerprint : str_repeat('b', 64),
        sourceVersion: 'provider-template-api-v3',
        observedAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
    );
}

it('certifies exact brand resolution is deterministic while brand revisions remain immutable', function () {
    $resolver = new BrandReferenceResolver;
    $v1 = task0035CertificationBrand('brand-v1');
    $v2 = $v1->fork(
        id: 'brand-v2',
        styleTokens: [
            new BrandStyleToken('spacing.base', BrandTokenKind::Spacing, '16px'),
            new BrandStyleToken('color.primary', BrandTokenKind::Color, '#111111'),
        ],
        identityMetadata: $v1->identityMetadata,
        assetReferences: $v1->assetReferences,
        defaults: $v1->defaults,
        createdByActorId: 'brand-editor',
        auditProvenance: ['source' => 'refresh'],
        idempotencyKey: 'idem-brand-v2',
        createdAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
        status: BrandVersionStatus::Published,
    );

    $first = $resolver->resolve('workspace-1', [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1')], [$v1]);
    $replay = $resolver->resolve('workspace-1', [new BrandReference('workspace-1', 'brand-kit-1', 'brand-v1')], [$v1]);

    expect($first->identity(new CanonicalJsonHasher))->toBe($replay->identity(new CanonicalJsonHasher))
        ->and($v1->styleTokenMap()['color.primary'])->toBe('#006039')
        ->and($v2->styleTokenMap()['color.primary'])->toBe('#111111')
        ->and($v2->parentVersionId)->toBe('brand-v1');
});

it('certifies approved global reusable components protect exact versions and report transitive impact', function () {
    $service = new ReusableComponentGovernanceService;
    $component = new ReusableComponent(
        id: 'component-1',
        workspaceId: 'workspace-1',
        name: 'Global CTA',
        lifecycle: ComponentLifecycle::Active,
        createdByActorId: 'component-admin',
        createdAt: new DateTimeImmutable('2026-09-20T12:30:00+00:00'),
    );
    $root = task0035CertificationVersion('component-v1', 'component-1');
    $governance = $service->initialize(
        $component,
        $root,
        ReusableComponentScope::Global,
        'reviewer-1',
        ['source' => 'certification'],
        new DateTimeImmutable('2026-09-20T12:31:00+00:00'),
    );
    $governance = $service->transition($governance, $component, $root, ReusableApprovalStatus::Ready, 'reviewer-1', [], new DateTimeImmutable('2026-09-20T12:32:00+00:00'));
    $governance = $service->transition($governance, $component, $root, ReusableApprovalStatus::Approved, 'reviewer-2', [], new DateTimeImmutable('2026-09-20T12:33:00+00:00'));

    $direct = task0035CertificationVersion(
        'dependent-v1',
        'component-2',
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'component-v1')],
    );
    $transitive = task0035CertificationVersion(
        'dependent-v2',
        'component-3',
        [new DependencyReference('workspace-1', DependencyKind::ComponentVersion, 'dependent-v1')],
    );

    $impact = $service->analyzeImpact($governance, $component, $root, [$root, $transitive, $direct]);
    $draft = $service->forkForEdit(
        $governance,
        $component,
        $root,
        'component-v2',
        [],
        'edit-v2',
        'component-editor',
        new DateTimeImmutable('2026-09-20T12:34:00+00:00'),
    );

    expect($governance->status())->toBe(ReusableApprovalStatus::Approved)
        ->and($impact->impactedVersionIds)->toBe(['dependent-v1', 'dependent-v2'])
        ->and($impact->requiresNewVersionForEdit)->toBeTrue()
        ->and($draft->id)->toBe('component-v2')
        ->and($draft->parentVersionId)->toBe('component-v1')
        ->and($root->id)->toBe('component-v1');
});

it('certifies provider derivatives are canonical-authoritative deterministic and capability-version aware', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $mapping = task0035CertificationMapping();
    $observation = task0035CertificationObservation();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    $first = $planner->plan(task0035CertificationRequest('retry-a'), $mapping, task0035CertificationCapability('2026-09'), $observation, $at);
    $retry = $planner->plan(task0035CertificationRequest('retry-b'), $mapping, task0035CertificationCapability('2026-09'), $observation, $at);
    $capabilityChanged = $planner->plan(task0035CertificationRequest('retry-c'), $mapping, task0035CertificationCapability('2026-10'), $observation, $at);

    expect($first->desiredDerivativeIdentity)->toBe($retry->desiredDerivativeIdentity)
        ->and($first->requestIdentity)->not->toBe($retry->requestIdentity)
        ->and($capabilityChanged->desiredDerivativeIdentity)->not->toBe($first->desiredDerivativeIdentity)
        ->and($first->provenance()['authoritative_source'])->toBeFalse()
        ->and($first->provenance()['network_fetch'])->toBeFalse()
        ->and($first->provenance()['live_publish'])->toBeFalse();
});

it('certifies external provider drift requires review and canonical advancement produces a new derivative', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $mapping = task0035CertificationMapping();
    $observation = task0035CertificationObservation();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');
    $v1 = task0035CertificationRequest();

    $initial = $planner->plan($v1, $mapping, task0035CertificationCapability(), $observation, $at);
    $synced = $mapping->withSynchronizedState('template-v1', $initial->desiredDerivativeIdentity, $observation->providerFingerprint, new DateTimeImmutable('2026-09-20T12:11:00+00:00'));

    $external = $planner->plan($v1, $synced, task0035CertificationCapability(), task0035CertificationObservation(str_repeat('c', 64)), new DateTimeImmutable('2026-09-20T12:12:00+00:00'));
    $advanced = $planner->plan(task0035CertificationRequest('sync-v2', 'template-v2'), $synced, task0035CertificationCapability(), $observation, new DateTimeImmutable('2026-09-20T12:12:00+00:00'));

    expect($external->drift)->toBe(ProviderTemplateDrift::ExternalProviderChange)
        ->and($external->action)->toBe(ProviderTemplateSyncAction::ReviewConflict)
        ->and($advanced->drift)->toBe(ProviderTemplateDrift::CanonicalVersionAdvanced)
        ->and($advanced->action)->toBe(ProviderTemplateSyncAction::Synchronize)
        ->and($synced->lastSyncedCanonicalVersionId)->toBe('template-v1');
});

it('certifies replay-safe synchronization claims exact retries and rejects conflicting idempotency reuse', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $ledger = new InMemoryProviderTemplateSyncLedger;
    $mapping = task0035CertificationMapping();
    $capability = task0035CertificationCapability();
    $observation = task0035CertificationObservation();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    $first = $planner->plan(task0035CertificationRequest('stable-key'), $mapping, $capability, $observation, $at);
    $replay = $planner->plan(task0035CertificationRequest('stable-key'), $mapping, $capability, $observation, $at);

    expect($ledger->claim($first))->toBe($first)
        ->and($ledger->claim($replay))->toBe($first);

    $conflict = $planner->plan(task0035CertificationRequest('stable-key', 'template-v2'), $mapping, $capability, $observation, $at);

    expect(fn () => $ledger->claim($conflict))
        ->toThrow(InvalidArgumentException::class, 'idempotency key conflicts');
});
