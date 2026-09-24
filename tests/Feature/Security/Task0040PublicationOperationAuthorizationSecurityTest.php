<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Publishing\Application\Publication\PublicationOperationAuthorizationService;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\Publishing\Task0040PublicationFixture;
use Tests\Support\Publishing\Task0040PublicationOperationFixture;

uses(RefreshDatabase::class);

it('authorizes retry only from canonical retriable failure evidence without mutating attempt or status history', function () {
    $fixture = Task0040PublicationFixture::create('operation-retry');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::FailedRetriable);
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Failed, 'retry');

    $beforeAttempt = DB::table('publication_attempts')->where('id', $attempt->id)->first();
    $beforeProjection = DB::table('publication_status_projections')->where('publication_attempt_id', $attempt->id)->first();
    $beforeObservationCount = DB::table('publication_status_observations')->count();

    $authorization = app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Retry,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    expect($authorization->providerCapabilityOperation)->toBe('publication.create')
        ->and($authorization->currentCapabilityEvidenceId)->toBe($fixture['providerCapabilityId'])
        ->and($authorization->originalCapabilityEvidenceId)->toBe($fixture['providerCapabilityId'])
        ->and($authorization->attemptHash)->toBe($attempt->attemptHash)
        ->and(strlen($authorization->authorizationHash))->toBe(64)
        ->and(DB::table('publication_status_observations')->count())->toBe($beforeObservationCount)
        ->and(DB::table('publication_attempts')->where('id', $attempt->id)->first())->toEqual($beforeAttempt)
        ->and(DB::table('publication_status_projections')->where('publication_attempt_id', $attempt->id)->first())
        ->toEqual($beforeProjection);
});

it('authorizes edit and delete only from a trusted successful publication and exact current capabilities', function () {
    $fixture = Task0040PublicationFixture::create('operation-success');
    $updateCapabilityId = Task0040PublicationOperationFixture::addCapability($fixture, 'publication.update', 'update');
    $deleteCapabilityId = Task0040PublicationOperationFixture::addCapability($fixture, 'publication.delete', 'delete');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Succeeded, 'success');

    $service = app(PublicationOperationAuthorizationService::class);
    $edit = $service->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Edit,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );
    $delete = $service->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Delete,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    expect($edit->providerCapabilityOperation)->toBe('publication.update')
        ->and($edit->currentCapabilityEvidenceId)->toBe($updateCapabilityId)
        ->and($delete->providerCapabilityOperation)->toBe('publication.delete')
        ->and($delete->currentCapabilityEvidenceId)->toBe($deleteCapabilityId)
        ->and($edit->authorizationHash)->not->toBe($delete->authorizationHash);
});

it('never authorizes retry for an already successful publication', function () {
    $fixture = Task0040PublicationFixture::create('operation-no-retry-success');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe(
        $fixture,
        $attempt,
        ProviderOperationStatus::Succeeded,
        'no-retry-success',
    );

    expect(fn () => app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Retry,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'failed_retriable');
});

it('fails closed on current provider scope loss', function () {
    $fixture = Task0040PublicationFixture::create('operation-scope-drift');
    Task0040PublicationOperationFixture::addCapability($fixture, 'publication.update', 'scope-drift');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Succeeded, 'scope-drift');

    DB::table('provider_connections')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerConnectionId'])
        ->update([
            'granted_scopes' => json_encode([], JSON_THROW_ON_ERROR),
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:30+00:00'),
        ]);

    expect(fn () => app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Edit,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'scopes are insufficient');
});

it('fails closed on current provider account-role loss', function () {
    $fixture = Task0040PublicationFixture::create('operation-role-drift');
    Task0040PublicationOperationFixture::addCapability($fixture, 'publication.delete', 'role-drift');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Succeeded, 'role-drift');

    DB::table('provider_connections')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerConnectionId'])
        ->update([
            'roles' => json_encode([], JSON_THROW_ON_ERROR),
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:30+00:00'),
        ]);

    expect(fn () => app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Delete,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'account roles are insufficient');
});

it('fails closed on provider app-review restriction', function () {
    $fixture = Task0040PublicationFixture::create('operation-review-drift');
    Task0040PublicationOperationFixture::addCapability($fixture, 'publication.update', 'review-drift');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Succeeded, 'review-drift');

    DB::table('provider_connections')
        ->where('workspace_id', $fixture['context']->workspaceId)
        ->where('id', $fixture['providerConnectionId'])
        ->update([
            'provider_review_status' => 'pending_review',
            'updated_at' => new DateTimeImmutable('2026-07-15T13:31:30+00:00'),
        ]);

    expect(fn () => app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Edit,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'app-review');
});

it('fails closed on a newer unsupported capability instead of falling back to older supported evidence', function () {
    $fixture = Task0040PublicationFixture::create('operation-capability-drift');
    Task0040PublicationOperationFixture::addCapability(
        $fixture,
        'publication.update',
        'older-supported',
        observedAt: '2026-07-15T13:30:40+00:00',
    );
    $newerUnsupported = Task0040PublicationOperationFixture::addCapability(
        $fixture,
        'publication.update',
        'newer-unsupported',
        support: 'unsupported',
        observedAt: '2026-07-15T13:31:20+00:00',
    );
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($fixture, PublicationAttemptState::Published);
    Task0040PublicationOperationFixture::observe(
        $fixture,
        $attempt,
        ProviderOperationStatus::Succeeded,
        'capability-drift',
    );

    expect(fn () => app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Edit,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'not supported');

    expect(DB::table('provider_capabilities')->where('id', $newerUnsupported)->value('support_status'))
        ->toBe('unsupported');
});

it('does not allow a foreign workspace to authorize an operation on another workspace attempt', function () {
    $inside = Task0040PublicationFixture::create('operation-inside');
    $outside = Task0040PublicationFixture::create('operation-outside');
    $attempt = Task0040PublicationOperationFixture::terminalAttempt($inside, PublicationAttemptState::FailedRetriable);
    Task0040PublicationOperationFixture::observe($inside, $attempt, ProviderOperationStatus::Failed, 'foreign');

    expect(fn () => app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $outside['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Retry,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'attempt access denied');
});
