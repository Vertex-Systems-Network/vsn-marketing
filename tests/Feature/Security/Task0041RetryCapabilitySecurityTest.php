<?php

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Publishing\Application\Operator\PublicationRetryActionService;
use App\Modules\Publishing\Application\Operator\PublicationRetryPreflightService;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\Publishing\Task0040PublicationFixture;
use Tests\Support\Publishing\Task0040PublicationOperationFixture;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $fixture
 */
function task0041RetryAttempt(
    array $fixture,
    int $targetIndex,
    PublicationAttemptState $terminal,
    ProviderOperationStatus $providerStatus,
    string $suffix,
): PublicationAttempt {
    $attempts = app(DatabasePublicationAttemptRepository::class);
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['targets'][$targetIndex]->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $attempt = $attempts->transitionState(
        $attempt->transitionTo(
            PublicationAttemptState::Dispatching,
            new DateTimeImmutable('2026-07-15T13:30:21+00:00'),
        ),
        1,
    );
    $attempt = $attempts->transitionState(
        $attempt->transitionTo($terminal, new DateTimeImmutable('2026-07-15T13:30:22+00:00')),
        2,
    );

    Task0040PublicationOperationFixture::observe($fixture, $attempt, $providerStatus, $suffix);

    return $attempt;
}

it('preflights only current backend-authorized retriable attempts with deterministic exclusions', function () {
    $fixture = Task0040PublicationFixture::create('task0041-retry-preflight', targetCount: 3);
    $retry = task0041RetryAttempt(
        $fixture,
        0,
        PublicationAttemptState::FailedRetriable,
        ProviderOperationStatus::Failed,
        'task0041-retry-eligible',
    );
    $success = task0041RetryAttempt(
        $fixture,
        1,
        PublicationAttemptState::Published,
        ProviderOperationStatus::Succeeded,
        'task0041-retry-success',
    );
    $terminal = task0041RetryAttempt(
        $fixture,
        2,
        PublicationAttemptState::FailedTerminal,
        ProviderOperationStatus::Failed,
        'task0041-retry-terminal',
    );

    $result = app(PublicationRetryPreflightService::class)->preflight(
        actor: $fixture['author'],
        context: $fixture['context'],
        publicationAttemptIds: [$terminal->id, $success->id, $retry->id],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    expect($result['confirmation_required'])->toBeTrue()
        ->and($result['provider_side_effect_executed'])->toBeFalse()
        ->and($result['counts'])->toBe([
            'candidate' => 3,
            'eligible' => 1,
            'excluded' => 2,
            'excluded_successful' => 1,
        ])
        ->and($result['eligible'][0]['publication_attempt_id'])->toBe($retry->id)
        ->and(strlen($result['confirmation_hash']))->toBe(64);

    $excluded = collect($result['excluded'])->keyBy('publication_attempt_id');

    expect($excluded[$success->id]['reason'])->toBe('already_successful')
        ->and($excluded[$terminal->id]['reason'])->toBe('not_retriable');

    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    expect($encoded)
        ->not->toContain('secret_reference')
        ->not->toContain('access_token')
        ->not->toContain('vault://')
        ->not->toContain($fixture['providerConnectionId'])
        ->not->toContain($fixture['providerCapabilityId']);
});

it('revalidates a confirmed retry without mutating canonical publication or provider status history', function () {
    $fixture = Task0040PublicationFixture::create('task0041-retry-confirm');
    $retry = task0041RetryAttempt(
        $fixture,
        0,
        PublicationAttemptState::FailedRetriable,
        ProviderOperationStatus::Failed,
        'task0041-confirm',
    );

    $preflight = app(PublicationRetryPreflightService::class)->preflight(
        actor: $fixture['author'],
        context: $fixture['context'],
        publicationAttemptIds: [$retry->id],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    $attemptBefore = DB::table('publication_attempts')->where('id', $retry->id)->first();
    $projectionBefore = DB::table('publication_status_projections')
        ->where('publication_attempt_id', $retry->id)
        ->first();
    $observationsBefore = DB::table('publication_status_observations')
        ->where('publication_attempt_id', $retry->id)
        ->count();

    $confirmed = app(PublicationRetryActionService::class)->confirm(
        actor: $fixture['author'],
        context: $fixture['context'],
        publicationAttemptIds: [$retry->id],
        confirmationHash: $preflight['confirmation_hash'],
        at: new DateTimeImmutable('2026-07-15T13:33:00+00:00'),
    );

    expect($confirmed['status'])->toBe('authorized')
        ->and($confirmed['provider_dispatch_authorized'])->toBeTrue()
        ->and($confirmed['provider_side_effect_executed'])->toBeFalse()
        ->and($confirmed['counts']['eligible'])->toBe(1)
        ->and($confirmed['items'][0]['publication_attempt_id'])->toBe($retry->id)
        ->and(DB::table('publication_attempts')->where('id', $retry->id)->first())->toEqual($attemptBefore)
        ->and(DB::table('publication_status_projections')->where('publication_attempt_id', $retry->id)->first())
        ->toEqual($projectionBefore)
        ->and(DB::table('publication_status_observations')->where('publication_attempt_id', $retry->id)->count())
        ->toBe($observationsBefore);
});

it('fails closed when provider capability authority changes after confirmation preflight', function () {
    $fixture = Task0040PublicationFixture::create('task0041-retry-drift');
    $retry = task0041RetryAttempt(
        $fixture,
        0,
        PublicationAttemptState::FailedRetriable,
        ProviderOperationStatus::Failed,
        'task0041-drift',
    );

    $preflight = app(PublicationRetryPreflightService::class)->preflight(
        actor: $fixture['author'],
        context: $fixture['context'],
        publicationAttemptIds: [$retry->id],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    DB::table('provider_capabilities')
        ->where('id', $fixture['providerCapabilityId'])
        ->update([
            'support_status' => 'unsupported',
            'source_version' => '2026-09-drifted',
            'updated_at' => new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
        ]);

    expect(fn () => app(PublicationRetryActionService::class)->confirm(
        actor: $fixture['author'],
        context: $fixture['context'],
        publicationAttemptIds: [$retry->id],
        confirmationHash: $preflight['confirmation_hash'],
        at: new DateTimeImmutable('2026-07-15T13:33:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'stale or does not match current authority');

    expect(DB::table('publication_attempts')->where('id', $retry->id)->value('state'))
        ->toBe(PublicationAttemptState::FailedRetriable->value);
});

it('requires campaign send permission and preserves workspace isolation', function () {
    $inside = Task0040PublicationFixture::create('task0041-retry-inside');
    $outside = Task0040PublicationFixture::create('task0041-retry-outside');
    $retry = task0041RetryAttempt(
        $inside,
        0,
        PublicationAttemptState::FailedRetriable,
        ProviderOperationStatus::Failed,
        'task0041-isolation',
    );

    $approverContext = new TenantContext(
        organizationId: $inside['context']->organizationId,
        workspaceId: $inside['context']->workspaceId,
        brandId: null,
        actorId: (string) $inside['approver']->getKey(),
    );

    expect(fn () => app(PublicationRetryPreflightService::class)->preflight(
        actor: $inside['approver'],
        context: $approverContext,
        publicationAttemptIds: [$retry->id],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'campaign.send');

    expect(fn () => app(PublicationRetryPreflightService::class)->preflight(
        actor: $outside['author'],
        context: $outside['context'],
        publicationAttemptIds: [$retry->id],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(AuthorizationException::class, 'access denied');
});

it('rejects duplicate candidates instead of widening a confirmed retry batch', function () {
    $fixture = Task0040PublicationFixture::create('task0041-retry-duplicate');
    $retry = task0041RetryAttempt(
        $fixture,
        0,
        PublicationAttemptState::FailedRetriable,
        ProviderOperationStatus::Failed,
        'task0041-duplicate',
    );

    expect(fn () => app(PublicationRetryPreflightService::class)->preflight(
        actor: $fixture['author'],
        context: $fixture['context'],
        publicationAttemptIds: [$retry->id, $retry->id],
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must be unique');
});
