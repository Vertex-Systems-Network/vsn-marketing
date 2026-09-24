<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Application\Publication\PublicationStatusReconciliationService;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0040 provider-status PostgreSQL tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0040 provider-status persistence certification requires PostgreSQL.');
    }
});

it('preserves append-only observation history across replay and re-entrant migration', function () {
    $fixture = Task0040PublicationFixture::create('status-pgsql');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $service = app(PublicationStatusReconciliationService::class);

    $first = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-pgsql',
        normalizedStatus: ProviderOperationStatus::Pending,
        providerStatus: 'PROCESSING',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-pgsql',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
        evidence: ['code' => 'processing'],
    );
    $duplicate = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-pgsql',
        normalizedStatus: ProviderOperationStatus::Pending,
        providerStatus: 'PROCESSING',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-pgsql',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:50+00:00'),
        evidence: ['code' => 'processing'],
    );

    expect($duplicate->observation->id)->toBe($first->observation->id)
        ->and(DB::table('publication_status_observations')->count())->toBe(1)
        ->and(DB::table('publication_status_projections')->count())->toBe(1);

    $migration = require database_path('migrations/2026_09_24_000003_create_publication_status_reconciliation_tables.php');
    $migration->up();

    expect(DB::table('publication_status_observations')->count())->toBe(1)
        ->and(DB::table('publication_status_projections')->count())->toBe(1);

    expect(fn () => DB::table('publication_status_observations')
        ->where('id', $first->observation->id)
        ->update(['provider_status' => 'REWRITTEN']))
        ->toThrow(QueryException::class);
});

it('database guards reject projection regression and terminal rewrite', function () {
    $fixture = Task0040PublicationFixture::create('status-guard');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $service = app(PublicationStatusReconciliationService::class);

    $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-guard',
        normalizedStatus: ProviderOperationStatus::InProgress,
        providerStatus: 'PROCESSING',
        source: ReconciliationSource::Polling,
        sourceReference: 'poll-processing',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );

    expect(fn () => DB::transaction(fn () => DB::table('publication_status_projections')
        ->where('publication_attempt_id', $attempt->id)
        ->update([
            'normalized_status' => 'pending',
            'provider_status' => 'QUEUED',
            'provider_observed_at' => new DateTimeImmutable('2026-07-15T13:31:10+00:00'),
            'projection_version' => 2,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:11+00:00'),
        ])))
        ->toThrow(QueryException::class);

    $terminal = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-guard',
        normalizedStatus: ProviderOperationStatus::Succeeded,
        providerStatus: 'PUBLISHED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-published',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:32:01+00:00'),
    );

    expect($terminal->projection->normalizedStatus)->toBe(ProviderOperationStatus::Succeeded);

    expect(fn () => DB::transaction(fn () => DB::table('publication_status_projections')
        ->where('publication_attempt_id', $attempt->id)
        ->update([
            'normalized_status' => 'failed',
            'provider_status' => 'FAILED',
            'provider_observed_at' => new DateTimeImmutable('2026-07-15T13:33:00+00:00'),
            'projection_version' => 3,
            'updated_at' => new DateTimeImmutable('2026-07-15T13:33:01+00:00'),
        ])))
        ->toThrow(QueryException::class);
});
