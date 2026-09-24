<?php

use App\Modules\Publishing\Application\Publication\ProviderMediaReferenceService;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceKind;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

it('registers one replay-safe derivative media reference without adding remote-fetch or credential fields', function () {
    $fixture = Task0040PublicationFixture::create('media-replay');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    $service = app(ProviderMediaReferenceService::class);
    $first = $service->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: 'provider-media-123',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    );
    $replayed = $service->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: 'provider-media-123',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    );

    expect($replayed->id)->toBe($first->id)
        ->and($replayed->canonicalAssetReferenceId)->toBe($fixture['assetReferenceId'])
        ->and($replayed->assetId)->toBe($fixture['assetId'])
        ->and(DB::table('publication_media_references')->count())->toBe(1)
        ->and(Schema::hasColumn('publication_media_references', 'remote_url'))->toBeFalse()
        ->and(Schema::hasColumn('publication_media_references', 'secret_reference'))->toBeFalse();
});

it('binds a snapshot-pinned variant to its immutable original lineage and output hash', function () {
    $fixture = Task0040PublicationFixture::create('media-variant', true);
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    $reference = app(ProviderMediaReferenceService::class)->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Container,
        providerReference: 'provider-container-123',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    );

    expect($reference->assetReferenceKind->value)->toBe('variant')
        ->and($reference->assetOriginalId)->toBe($fixture['assetOriginalId'])
        ->and($reference->assetVariantId)->toBe($fixture['assetVariantId'])
        ->and($reference->canonicalAssetReferenceId)->toBe($fixture['assetVariantId'])
        ->and($reference->assetContentSha256)->toBe(hash('sha256', 'task0040-variant-media-variant'));
});

it('rejects arbitrary remote-media URLs before persistence', function () {
    $fixture = Task0040PublicationFixture::create('remote-url');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    expect(fn () => app(ProviderMediaReferenceService::class)->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Upload,
        providerReference: 'https://attacker.example/internal',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'remote-media URL');

    expect(DB::table('publication_media_references')->count())->toBe(0);
});

it('fails closed when provider scope authority drifts after publication-attempt preparation', function () {
    $fixture = Task0040PublicationFixture::create('media-scope-drift');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    DB::table('provider_connections')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerConnectionId'])
        ->update([
            'granted_scopes' => json_encode([], JSON_THROW_ON_ERROR),
            'updated_at' => new DateTimeImmutable('2026-07-15T13:30:25+00:00'),
        ]);

    expect(fn () => app(ProviderMediaReferenceService::class)->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: 'provider-media-scope-drift',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'scopes or roles are insufficient');

    expect(DB::table('publication_media_references')->count())->toBe(0);
});

it('does not allow a foreign workspace to attach media derivatives to another workspace attempt', function () {
    $inside = Task0040PublicationFixture::create('media-inside');
    $outside = Task0040PublicationFixture::create('media-outside');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $inside['context']->workspaceId,
        executionIntentId: $inside['executionIntent']->id,
        targetId: $inside['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    expect(fn () => app(ProviderMediaReferenceService::class)->registerObservedReference(
        workspaceId: $outside['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $inside['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: 'provider-media-foreign',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    ))->toThrow(AuthorizationException::class, 'Publication attempt access denied');

    expect(DB::table('publication_media_references')->count())->toBe(0);
});
