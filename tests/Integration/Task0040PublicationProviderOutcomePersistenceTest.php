<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Publishing\Application\Publication\PublicationOperationAuthorizationService;
use App\Modules\Publishing\Application\Publication\PublicationProviderOutcomeService;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use App\Modules\Publishing\Domain\Publication\PublicationPolicyBoundaryEvidence;
use App\Modules\Publishing\Domain\Publication\PublicationProviderCircuitState;
use App\Modules\Publishing\Domain\Publication\PublicationProviderOutcomeCode;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;
use Tests\Support\Publishing\Task0040PublicationOperationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0040 provider-outcome PostgreSQL tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0040 provider-outcome certification requires PostgreSQL.');
    }
});

it('detects latest capability-version drift without rewriting canonical publication history', function () {
    $fixture = Task0040PublicationFixture::create('outcome-pgsql');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt(
        $fixture,
        PublicationAttemptState::FailedRetriable,
    );
    Task0040PublicationOperationFixture::observe(
        $fixture,
        $attempt,
        ProviderOperationStatus::Failed,
        'outcome-pgsql',
    );
    $authorization = app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Retry,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    $attemptBefore = DB::table('publication_attempts')->where('id', $attempt->id)->first();
    $projectionBefore = DB::table('publication_status_projections')->where('publication_attempt_id', $attempt->id)->first();
    $observationsBefore = DB::table('publication_status_observations')->count();

    $newestCapabilityId = Task0040PublicationOperationFixture::addCapability(
        $fixture,
        'publication.create',
        'outcome-pgsql-newer',
        observedAt: '2026-07-15T13:32:10+00:00',
    );

    $outcome = app(PublicationProviderOutcomeService::class)->assess(
        authorization: $authorization,
        boundaries: new PublicationPolicyBoundaryEvidence(true, true, true, true, true, true),
        circuitState: PublicationProviderCircuitState::Closed,
        providerError: null,
        at: new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    expect($outcome->code)->toBe(PublicationProviderOutcomeCode::CapabilityVersionDrift)
        ->and($outcome->currentCapabilityEvidenceId)->toBe($newestCapabilityId)
        ->and($outcome->fallbackAllowed)->toBeFalse()
        ->and(DB::table('publication_attempts')->where('id', $attempt->id)->first())->toEqual($attemptBefore)
        ->and(DB::table('publication_status_projections')->where('publication_attempt_id', $attempt->id)->first())
        ->toEqual($projectionBefore)
        ->and(DB::table('publication_status_observations')->count())->toBe($observationsBefore);
});
