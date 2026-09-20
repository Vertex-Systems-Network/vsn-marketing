<?php

use App\Modules\Content\Domain\Brand\BrandKit;
use App\Modules\Content\Domain\Brand\BrandStyleToken;
use App\Modules\Content\Domain\Brand\BrandTokenKind;
use App\Modules\Content\Domain\Brand\BrandVersion;
use App\Modules\Providers\Application\TemplateSync\ProviderTemplateSyncPlanner;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\Templates\ProviderTemplateMapping;
use App\Modules\Providers\Domain\Templates\ProviderTemplateObservation;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncRequest;
use App\Modules\Templates\Domain\Governance\ReusableApprovalStatus;
use App\Modules\Templates\Domain\Governance\ReusableGovernanceEvent;
use DateTimeImmutable;
use InvalidArgumentException;

it('rejects secrets and provider-owned identifiers from canonical brand metadata', function () {
    $kit = new BrandKit('brand-kit-1', 'workspace-1', 'VSN', 'brand-admin', new DateTimeImmutable('2026-09-20T12:00:00+00:00'));

    expect(fn () => BrandVersion::initialFor(
        brandKit: $kit,
        id: 'brand-v1',
        styleTokens: [new BrandStyleToken('color.primary', BrandTokenKind::Color, '#006039')],
        identityMetadata: ['nested' => ['authorization_token' => 'secret']],
        assetReferences: ['asset-1'],
        defaults: ['provider_template_id' => 'remote-template'],
        createdByActorId: 'brand-admin',
        auditProvenance: ['source' => 'security-certification'],
        idempotencyKey: 'brand-v1',
        createdAt: new DateTimeImmutable('2026-09-20T12:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or provider-owned brand metadata key is forbidden');
});

it('rejects PHASE-07 publishing scheduling campaign and secret data from reusable governance audit provenance', function () {
    expect(fn () => new ReusableGovernanceEvent(
        sequence: 1,
        fromStatus: null,
        toStatus: ReusableApprovalStatus::Draft,
        actorId: 'reviewer-1',
        auditProvenance: ['nested' => ['campaign_id' => 'campaign-1']],
        occurredAt: new DateTimeImmutable('2026-09-20T12:30:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or PHASE-07 governance audit key is forbidden');

    expect(fn () => new ReusableGovernanceEvent(
        sequence: 1,
        fromStatus: null,
        toStatus: ReusableApprovalStatus::Draft,
        actorId: 'reviewer-1',
        auditProvenance: ['publishing_state' => 'live'],
        occurredAt: new DateTimeImmutable('2026-09-20T12:30:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or PHASE-07 governance audit key is forbidden');
});

it('fails closed when provider capability mapping or observation crosses workspace boundaries', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = new ProviderTemplateSyncRequest(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        canonicalTemplateVersionId: 'template-v1',
        canonicalSourceIdentity: str_repeat('a', 64),
        componentVersionIds: ['component-v1'],
        brandVersionIds: ['brand-v1'],
        templateKind: 'email',
        mediaKinds: ['image'],
        idempotencyKey: 'sync-1',
    );
    $mapping = new ProviderTemplateMapping(
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
    $capability = new ProviderCapability(
        id: 'capability-1',
        workspaceId: 'workspace-2',
        providerId: 'provider-1',
        connectionId: 'connection-secret',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'media_kinds' => ['image']],
        sourceUrl: 'https://example.test/provider-docs',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-20T12:00:00+00:00'),
    );

    expect(fn () => $planner->plan($request, $mapping, $capability, null, new DateTimeImmutable('2026-09-20T12:10:00+00:00')))
        ->toThrow(InvalidArgumentException::class, 'cannot cross workspace or provider boundaries');
});

it('rejects sensitive provider capability evidence and keeps credentials out of sync provenance', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = new ProviderTemplateSyncRequest(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        canonicalTemplateId: 'template-1',
        canonicalTemplateVersionId: 'template-v1',
        canonicalSourceIdentity: str_repeat('a', 64),
        componentVersionIds: ['component-v1'],
        brandVersionIds: ['brand-v1'],
        templateKind: 'email',
        mediaKinds: ['image'],
        idempotencyKey: 'sync-1',
    );
    $mapping = new ProviderTemplateMapping(
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
    $observation = new ProviderTemplateObservation(
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        providerTemplateReference: 'provider-template-42',
        providerFingerprint: str_repeat('b', 64),
        sourceVersion: 'provider-api-v3',
        observedAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
    );

    $unsafe = new ProviderCapability(
        id: 'capability-1',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        connectionId: 'connection-secret',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'authorization_token' => 'secret'],
        sourceUrl: 'https://example.test/provider-docs',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-20T12:00:00+00:00'),
    );

    expect(fn () => $planner->plan($request, $mapping, $unsafe, $observation, new DateTimeImmutable('2026-09-20T12:10:00+00:00')))
        ->toThrow(InvalidArgumentException::class, 'Sensitive provider template capability key is forbidden');

    $safe = new ProviderCapability(
        id: 'capability-1',
        workspaceId: 'workspace-1',
        providerId: 'provider-1',
        connectionId: 'connection-secret',
        operation: 'template.sync',
        support: CapabilitySupport::Supported,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: ['template_kinds' => ['email'], 'media_kinds' => ['image']],
        sourceUrl: 'https://example.test/provider-docs',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-20T12:00:00+00:00'),
    );
    $plan = $planner->plan($request, $mapping, $safe, $observation, new DateTimeImmutable('2026-09-20T12:10:00+00:00'));
    $encoded = json_encode($plan->provenance(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('connection-secret')
        ->and($encoded)->not->toContain('templates.write')
        ->and($encoded)->not->toContain('credential')
        ->and($encoded)->not->toContain('upload_id')
        ->and($plan->provenance()['live_publish'])->toBeFalse()
        ->and($plan->provenance()['network_fetch'])->toBeFalse();
});
