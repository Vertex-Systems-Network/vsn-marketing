<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Publishing\Application\Publication\PublicationOperationAuthorizationService;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;
use Tests\Support\Publishing\Task0040PublicationOperationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0040 operation authorization PostgreSQL tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0040 operation authorization certification requires PostgreSQL.');
    }
});

it('deterministically selects the newest exact operation evidence and preserves immutable publication history', function () {
    $fixture = Task0040PublicationFixture::create('operation-pgsql');
    Task0040PublicationOperationFixture::addCapability(
        $fixture,
        'publication.delete',
        'pgsql-older-supported',
        observedAt: '2026-07-15T13:30:40+00:00',
    );
    $newestCapabilityId = Task0040PublicationOperationFixture::addCapability(
        $fixture,
        'publication.delete',
        'pgsql-newest-supported',
        observedAt: '2026-07-15T13:31:20+00:00',
    );
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Succeeded, 'pgsql');

    $attemptBefore = DB::table('publication_attempts')->where('id', $attempt->id)->first();
    $projectionBefore = DB::table('publication_status_projections')
        ->where('publication_attempt_id', $attempt->id)
        ->first();
    $observationsBefore = DB::table('publication_status_observations')->count();

    $authorization = app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Delete,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    expect($authorization->currentCapabilityEvidenceId)->toBe($newestCapabilityId)
        ->and(DB::table('publication_attempts')->where('id', $attempt->id)->first())->toEqual($attemptBefore)
        ->and(DB::table('publication_status_projections')->where('publication_attempt_id', $attempt->id)->first())
        ->toEqual($projectionBefore)
        ->and(DB::table('publication_status_observations')->count())->toBe($observationsBefore);
});
