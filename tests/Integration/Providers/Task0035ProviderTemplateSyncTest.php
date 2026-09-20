<?php

use App\Modules\Providers\Application\TemplateSync\ProviderTemplateSyncPlanner;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\Templates\ProviderTemplateDrift;
use App\Modules\Providers\Domain\Templates\ProviderTemplateMapping;
use App\Modules\Providers\Domain\Templates\ProviderTemplateObservation;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncAction;
use App\Modules\Providers\Domain\Templates\ProviderTemplateSyncRequest;
use App\Modules\Providers\Infrastructure\TemplateSync\InMemoryProviderTemplateSyncLedger;
use DateTimeImmutable;
use InvalidArgumentException;

function task0035ProviderCapability(
    CapabilitySupport $support = CapabilitySupport::Supported,
    array $constraints = [
        'template_kinds' => ['email'],
        'media_kinds' => ['image', 'text'],
    ],
    ?string $sourceVersion = '2026-09',
    string $workspaceId = 'workspace-1',
    string $providerId = 'provider-1',
    ?DateTimeImmutable $observedAt = null,
    ?DateTimeImmutable $freshUntil = null,
): ProviderCapability {
    return new ProviderCapability(
        id: 'capability-1',
        workspaceId: $workspaceId,
        providerId: $providerId,
        connectionId: 'connection-1',
        operation: 'template.sync',
        support: $support,
        requiredScopes: ['templates.write'],
        requiredRoles: [],
        constraints: $constraints,
        sourceUrl: 'https://example.test/provider-template-docs',
        sourceVersion: $sourceVersion,
        observedAt: $observedAt ?? new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        freshUntil: $freshUntil ?? new DateTimeImmutable('2026-10-20T12:00:00+00:00'),
    );
}

function task0035ProviderSyncRequest(
    string $idempotencyKey = 'sync-1',
    string $canonicalVersionId = 'template-v1',
    string $canonicalSourceIdentity = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $componentVersionIds = ['component-v2', 'component-v1'],
    array $brandVersionIds = ['brand-v2', 'brand-v1'],
    array $mediaKinds = ['text', 'image'],
    string $workspaceId = 'workspace-1',
    string $providerId = 'provider-1',
): ProviderTemplateSyncRequest {
    return new ProviderTemplateSyncRequest(
        workspaceId: $workspaceId,
        providerId: $providerId,
        canonicalTemplateId: 'template-1',
        canonicalTemplateVersionId: $canonicalVersionId,
        canonicalSourceIdentity: $canonicalSourceIdentity,
        componentVersionIds: $componentVersionIds,
        brandVersionIds: $brandVersionIds,
        templateKind: 'email',
        mediaKinds: $mediaKinds,
        idempotencyKey: $idempotencyKey,
    );
}

function task0035ProviderMapping(): ProviderTemplateMapping
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

function task0035ProviderObservation(
    string $fingerprint = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    string $workspaceId = 'workspace-1',
    string $providerId = 'provider-1',
    string $reference = 'provider-template-42',
): ProviderTemplateObservation {
    return new ProviderTemplateObservation(
        workspaceId: $workspaceId,
        providerId: $providerId,
        providerTemplateReference: $reference,
        providerFingerprint: $fingerprint,
        sourceVersion: 'provider-template-api-v3',
        observedAt: new DateTimeImmutable('2026-09-20T12:05:00+00:00'),
    );
}

it('creates deterministic provider derivative identity from exact canonical and capability inputs', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $mapping = task0035ProviderMapping();
    $observation = task0035ProviderObservation();

    $left = $planner->plan(
        request: task0035ProviderSyncRequest(),
        mapping: $mapping,
        capability: task0035ProviderCapability(),
        observation: $observation,
        at: new DateTimeImmutable('2026-09-20T12:10:00+00:00'),
    );
    $right = $planner->plan(
        request: task0035ProviderSyncRequest(
            idempotencyKey: 'retry-key-2',
            componentVersionIds: ['component-v1', 'component-v2'],
            brandVersionIds: ['brand-v1', 'brand-v2'],
            mediaKinds: ['image', 'text'],
        ),
        mapping: $mapping,
        capability: task0035ProviderCapability(constraints: [
            'media_kinds' => ['text', 'image'],
            'template_kinds' => ['email'],
        ]),
        observation: $observation,
        at: new DateTimeImmutable('2026-09-20T12:10:00+00:00'),
    );

    expect($left->desiredDerivativeIdentity)->toBe($right->desiredDerivativeIdentity)
        ->and($left->requestIdentity)->not->toBe($right->requestIdentity)
        ->and($left->drift)->toBe(ProviderTemplateDrift::CanonicalVersionAdvanced)
        ->and($left->action)->toBe(ProviderTemplateSyncAction::Synchronize)
        ->and($left->provenance()['authoritative_source'])->toBeFalse();
});

it('detects exact in-sync replay after recording synchronized provider evidence', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = task0035ProviderSyncRequest();
    $capability = task0035ProviderCapability();
    $observation = task0035ProviderObservation();
    $initial = task0035ProviderMapping();

    $planned = $planner->plan(
        $request,
        $initial,
        $capability,
        $observation,
        new DateTimeImmutable('2026-09-20T12:10:00+00:00'),
    );
    $synced = $initial->withSynchronizedState(
        canonicalVersionId: $request->canonicalTemplateVersionId,
        derivativeIdentity: $planned->desiredDerivativeIdentity,
        providerFingerprint: $observation->providerFingerprint,
        at: new DateTimeImmutable('2026-09-20T12:11:00+00:00'),
    );

    $replayed = $planner->plan(
        $request,
        $synced,
        $capability,
        $observation,
        new DateTimeImmutable('2026-09-20T12:12:00+00:00'),
    );

    expect($replayed->drift)->toBe(ProviderTemplateDrift::InSync)
        ->and($replayed->action)->toBe(ProviderTemplateSyncAction::None)
        ->and($replayed->canSynchronize())->toBeFalse()
        ->and($synced->lastSyncedCanonicalVersionId)->toBe('template-v1');
});

it('treats external provider changes as conflicts and never silently overwrites canonical authority', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = task0035ProviderSyncRequest();
    $capability = task0035ProviderCapability();
    $baselineObservation = task0035ProviderObservation();
    $mapping = task0035ProviderMapping();
    $baselinePlan = $planner->plan(
        $request,
        $mapping,
        $capability,
        $baselineObservation,
        new DateTimeImmutable('2026-09-20T12:10:00+00:00'),
    );
    $synced = $mapping->withSynchronizedState(
        $request->canonicalTemplateVersionId,
        $baselinePlan->desiredDerivativeIdentity,
        $baselineObservation->providerFingerprint,
        new DateTimeImmutable('2026-09-20T12:11:00+00:00'),
    );

    $external = $planner->plan(
        $request,
        $synced,
        $capability,
        task0035ProviderObservation(
            fingerprint: 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
        ),
        new DateTimeImmutable('2026-09-20T12:12:00+00:00'),
    );

    expect($external->drift)->toBe(ProviderTemplateDrift::ExternalProviderChange)
        ->and($external->action)->toBe(ProviderTemplateSyncAction::ReviewConflict)
        ->and($external->canonicalTemplateVersionId)->toBe('template-v1')
        ->and($external->provenance()['live_publish'])->toBeFalse()
        ->and($external->provenance()['network_fetch'])->toBeFalse();
});

it('detects canonical version advancement without mutating the previously synchronized mapping', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $v1 = task0035ProviderSyncRequest();
    $capability = task0035ProviderCapability();
    $observation = task0035ProviderObservation();
    $mapping = task0035ProviderMapping();
    $v1Plan = $planner->plan(
        $v1,
        $mapping,
        $capability,
        $observation,
        new DateTimeImmutable('2026-09-20T12:10:00+00:00'),
    );
    $synced = $mapping->withSynchronizedState(
        'template-v1',
        $v1Plan->desiredDerivativeIdentity,
        $observation->providerFingerprint,
        new DateTimeImmutable('2026-09-20T12:11:00+00:00'),
    );

    $v2 = task0035ProviderSyncRequest(
        canonicalVersionId: 'template-v2',
        canonicalSourceIdentity: 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    );
    $next = $planner->plan(
        $v2,
        $synced,
        $capability,
        $observation,
        new DateTimeImmutable('2026-09-20T12:12:00+00:00'),
    );

    expect($next->drift)->toBe(ProviderTemplateDrift::CanonicalVersionAdvanced)
        ->and($next->action)->toBe(ProviderTemplateSyncAction::Synchronize)
        ->and($synced->lastSyncedCanonicalVersionId)->toBe('template-v1')
        ->and($next->canonicalTemplateVersionId)->toBe('template-v2');
});

it('returns deterministic missing mapping provider missing and capability fallback outcomes', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = task0035ProviderSyncRequest();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    $missingMapping = $planner->plan(
        $request,
        null,
        task0035ProviderCapability(),
        null,
        $at,
    );
    $providerMissing = $planner->plan(
        $request,
        task0035ProviderMapping(),
        task0035ProviderCapability(),
        null,
        $at,
    );
    $unsupported = $planner->plan(
        $request,
        task0035ProviderMapping(),
        task0035ProviderCapability(support: CapabilitySupport::Unsupported),
        task0035ProviderObservation(),
        $at,
    );
    $stale = $planner->plan(
        $request,
        task0035ProviderMapping(),
        task0035ProviderCapability(
            freshUntil: new DateTimeImmutable('2026-09-20T12:09:59+00:00'),
        ),
        task0035ProviderObservation(),
        $at,
    );
    $incompatible = $planner->plan(
        $request,
        task0035ProviderMapping(),
        task0035ProviderCapability(constraints: [
            'template_kinds' => ['transactional'],
            'media_kinds' => ['text'],
        ]),
        task0035ProviderObservation(),
        $at,
    );

    expect($missingMapping->drift)->toBe(ProviderTemplateDrift::MissingMapping)
        ->and($missingMapping->action)->toBe(ProviderTemplateSyncAction::Blocked)
        ->and($providerMissing->drift)->toBe(ProviderTemplateDrift::ProviderTemplateMissing)
        ->and($providerMissing->action)->toBe(ProviderTemplateSyncAction::Synchronize)
        ->and($unsupported->drift)->toBe(ProviderTemplateDrift::CapabilityUnavailable)
        ->and($unsupported->action)->toBe(ProviderTemplateSyncAction::UseFallback)
        ->and($stale->action)->toBe(ProviderTemplateSyncAction::UseFallback)
        ->and($incompatible->action)->toBe(ProviderTemplateSyncAction::UseFallback);
});

it('changes provider derivative identity when versioned capability evidence changes', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = task0035ProviderSyncRequest();
    $mapping = task0035ProviderMapping();
    $observation = task0035ProviderObservation();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    $v1 = $planner->plan(
        $request,
        $mapping,
        task0035ProviderCapability(sourceVersion: '2026-09'),
        $observation,
        $at,
    );
    $v2 = $planner->plan(
        $request,
        $mapping,
        task0035ProviderCapability(sourceVersion: '2026-10'),
        $observation,
        $at,
    );

    expect($v1->desiredDerivativeIdentity)->not->toBe($v2->desiredDerivativeIdentity)
        ->and($v1->capabilitySourceVersion)->toBe('2026-09')
        ->and($v2->capabilitySourceVersion)->toBe('2026-10');
});

it('claims exact synchronization replays idempotently and rejects conflicting reuse', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $ledger = new InMemoryProviderTemplateSyncLedger;
    $mapping = task0035ProviderMapping();
    $capability = task0035ProviderCapability();
    $observation = task0035ProviderObservation();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    $first = $planner->plan(
        task0035ProviderSyncRequest(idempotencyKey: 'stable-sync-key'),
        $mapping,
        $capability,
        $observation,
        $at,
    );
    $replay = $planner->plan(
        task0035ProviderSyncRequest(idempotencyKey: 'stable-sync-key'),
        $mapping,
        $capability,
        $observation,
        $at,
    );

    expect($ledger->claim($first))->toBe($first)
        ->and($ledger->claim($replay))->toBe($first)
        ->and($ledger->find('workspace-1', 'stable-sync-key'))->toBe($first);

    $conflict = $planner->plan(
        task0035ProviderSyncRequest(
            idempotencyKey: 'stable-sync-key',
            canonicalVersionId: 'template-v2',
            canonicalSourceIdentity: 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        ),
        $mapping,
        $capability,
        $observation,
        $at,
    );

    expect(fn () => $ledger->claim($conflict))
        ->toThrow(InvalidArgumentException::class, 'idempotency key conflicts');
});

it('fails closed across workspace provider mapping observation and capability boundaries', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = task0035ProviderSyncRequest();
    $mapping = task0035ProviderMapping();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    expect(fn () => $planner->plan(
        $request,
        $mapping,
        task0035ProviderCapability(workspaceId: 'workspace-2'),
        task0035ProviderObservation(),
        $at,
    ))->toThrow(InvalidArgumentException::class, 'cannot cross workspace or provider boundaries');

    expect(fn () => $planner->plan(
        $request,
        new ProviderTemplateMapping(
            id: 'mapping-foreign',
            workspaceId: 'workspace-2',
            providerId: 'provider-1',
            canonicalTemplateId: 'template-1',
            providerTemplateReference: 'provider-template-42',
            lastSyncedCanonicalVersionId: null,
            lastSyncedDerivativeIdentity: null,
            lastObservedProviderFingerprint: null,
            createdAt: new DateTimeImmutable('2026-09-20T12:00:00+00:00'),
        ),
        task0035ProviderCapability(),
        null,
        $at,
    ))->toThrow(InvalidArgumentException::class, 'does not match the requested workspace/provider/canonical template');

    expect(fn () => $planner->plan(
        $request,
        $mapping,
        task0035ProviderCapability(),
        task0035ProviderObservation(reference: 'provider-template-other'),
        $at,
    ))->toThrow(InvalidArgumentException::class, 'does not match the explicit mapping boundary');
});

it('rejects sensitive capability evidence and keeps credentials upload identifiers and raw provider payloads out of provenance', function () {
    $planner = new ProviderTemplateSyncPlanner;
    $request = task0035ProviderSyncRequest();
    $mapping = task0035ProviderMapping();
    $observation = task0035ProviderObservation();
    $at = new DateTimeImmutable('2026-09-20T12:10:00+00:00');

    expect(fn () => $planner->plan(
        $request,
        $mapping,
        task0035ProviderCapability(constraints: [
            'template_kinds' => ['email'],
            'authorization_token' => 'secret',
        ]),
        $observation,
        $at,
    ))->toThrow(InvalidArgumentException::class, 'Sensitive provider template capability key is forbidden');

    $safe = $planner->plan(
        $request,
        $mapping,
        task0035ProviderCapability(),
        $observation,
        $at,
    );
    $encoded = json_encode($safe->provenance(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('connection-1')
        ->and($encoded)->not->toContain('templates.write')
        ->and($encoded)->not->toContain('authorization_token')
        ->and($encoded)->not->toContain('upload_id')
        ->and($encoded)->not->toContain('credential')
        ->and($encoded)->not->toContain('raw_payload')
        ->and($safe->provenance()['authoritative_source'])->toBeFalse()
        ->and($safe->provenance()['network_fetch'])->toBeFalse()
        ->and($safe->provenance()['live_publish'])->toBeFalse();
});
