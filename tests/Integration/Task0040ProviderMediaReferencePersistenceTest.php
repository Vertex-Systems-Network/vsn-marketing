<?php

use App\Modules\Publishing\Application\Publication\ProviderMediaReferenceService;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceState;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0040 provider-media PostgreSQL tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0040 provider-media persistence certification requires PostgreSQL.');
    }
});

it('preserves derivative authority across replay and re-entrant migration', function () {
    $fixture = Task0040PublicationFixture::create('media-pgsql');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    $service = app(ProviderMediaReferenceService::class);
    $reference = $service->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: 'provider-media-pgsql',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    );
    $replayed = $service->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: 'provider-media-pgsql',
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    );

    expect($replayed->id)->toBe($reference->id)
        ->and($replayed->referenceHash)->toBe($reference->referenceHash)
        ->and(DB::table('publication_media_references')->count())->toBe(1);

    $migration = require database_path('migrations/2026_09_24_000002_create_publication_media_reference_tables.php');
    $migration->up();

    expect(DB::table('publication_media_references')->count())->toBe(1);

    expect(fn () => DB::table('publication_media_references')
        ->where('id', $reference->id)
        ->update([
            'provider_reference' => 'rewritten-provider-id',
            'state_version' => 2,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        ]))
        ->toThrow(QueryException::class);
});

it('enforces monotonic processing and explicit expiration in PostgreSQL', function () {
    $fixture = Task0040PublicationFixture::create('media-state-machine');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    $service = app(ProviderMediaReferenceService::class);
    $pending = $service->registerObservedReference(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        assetReferenceId: $fixture['assetReferenceId'],
        providerReferenceKind: ProviderMediaReferenceKind::Container,
        providerReference: 'provider-container-state',
        expiresAt: new DateTimeImmutable('2026-07-15T13:35:00+00:00'),
        observedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    );
    $processing = $service->transition(
        workspaceId: $fixture['context']->workspaceId,
        referenceId: $pending->id,
        next: ProviderMediaReferenceState::Processing,
        observedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );
    $ready = $service->transition(
        workspaceId: $fixture['context']->workspaceId,
        referenceId: $processing->id,
        next: ProviderMediaReferenceState::Ready,
        observedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );
    $expired = $service->transition(
        workspaceId: $fixture['context']->workspaceId,
        referenceId: $ready->id,
        next: ProviderMediaReferenceState::Expired,
        observedAt: new DateTimeImmutable('2026-07-15T13:36:00+00:00'),
    );

    expect($expired->state)->toBe(ProviderMediaReferenceState::Expired)
        ->and($expired->stateVersion)->toBe(4);

    expect(fn () => DB::table('publication_media_references')
        ->where('id', $expired->id)
        ->update([
            'state' => 'processing',
            'state_version' => 5,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:37:00+00:00'),
        ]))
        ->toThrow(QueryException::class);
});
