<?php

use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Publishing\Application\Publication\PublicationOperationAuthorizationService;
use App\Modules\Publishing\Application\Publication\PublicationProviderOutcomeService;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use App\Modules\Publishing\Domain\Publication\PublicationOperationAuthorization;
use App\Modules\Publishing\Domain\Publication\PublicationPolicyBoundaryEvidence;
use App\Modules\Publishing\Domain\Publication\PublicationProviderCircuitState;
use App\Modules\Publishing\Domain\Publication\PublicationProviderOutcomeCode;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;
use Tests\Support\Publishing\Task0040PublicationOperationFixture;

uses(RefreshDatabase::class);

function task0040ProviderOutcomeBoundaries(
    bool $workspaceAllowed = true,
    bool $approvalValid = true,
    bool $consentSuppressionAllowed = true,
    bool $senderContentAllowed = true,
    bool $assetAllowed = true,
    bool $providerPolicyAllowed = true,
): PublicationPolicyBoundaryEvidence {
    return new PublicationPolicyBoundaryEvidence(
        workspaceAllowed: $workspaceAllowed,
        approvalValid: $approvalValid,
        consentSuppressionAllowed: $consentSuppressionAllowed,
        senderContentAllowed: $senderContentAllowed,
        assetAllowed: $assetAllowed,
        providerPolicyAllowed: $providerPolicyAllowed,
    );
}

/** @return array{array<string, mixed>, PublicationOperationAuthorization} */
function task0040RetryOutcomeAuthorization(string $suffix): array
{
    $fixture = Task0040PublicationFixture::create($suffix);
    $attempt = Task0040PublicationOperationFixture::terminalAttempt(
        $fixture,
        PublicationAttemptState::FailedRetriable,
    );
    Task0040PublicationOperationFixture::observe($fixture, $attempt, ProviderOperationStatus::Failed, $suffix);

    $authorization = app(PublicationOperationAuthorizationService::class)->authorize(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        operation: PublicationOperation::Retry,
        at: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );

    return [$fixture, $authorization];
}

it('returns ready only when policy and current provider authority still pass', function () {
    [$fixture, $authorization] = task0040RetryOutcomeAuthorization('outcome-ready');

    $outcome = app(PublicationProviderOutcomeService::class)->assess(
        authorization: $authorization,
        boundaries: task0040ProviderOutcomeBoundaries(),
        circuitState: PublicationProviderCircuitState::Closed,
        providerError: null,
        at: new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    expect($outcome->code)->toBe(PublicationProviderOutcomeCode::Ready)
        ->and($outcome->workHeld)->toBeFalse()
        ->and($outcome->retryEligible)->toBeFalse()
        ->and($outcome->fallbackAllowed)->toBeFalse()
        ->and($outcome->currentCapabilityEvidenceId)->toBe($fixture['providerCapabilityId']);
});

it('keeps policy boundary denial authoritative over retryable provider evidence', function () {
    [, $authorization] = task0040RetryOutcomeAuthorization('outcome-policy-deny');

    $error = new NormalizedProviderError(
        category: ProviderErrorCategory::Retryable,
        message: 'transient failure with token-secret-that-must-not-persist',
        providerCode: 'temporary',
        evidence: ['access_token' => 'secret-value'],
    );
    $outcome = app(PublicationProviderOutcomeService::class)->assess(
        authorization: $authorization,
        boundaries: task0040ProviderOutcomeBoundaries(approvalValid: false),
        circuitState: PublicationProviderCircuitState::Closed,
        providerError: $error,
        at: new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );
    $encoded = json_encode($outcome->canonicalPayload(), JSON_THROW_ON_ERROR);

    expect($outcome->code)->toBe(PublicationProviderOutcomeCode::PolicyBoundaryDenied)
        ->and($outcome->failedPolicyBoundary)->toBe('approval_valid')
        ->and($outcome->retryEligible)->toBeFalse()
        ->and($outcome->fallbackAllowed)->toBeFalse()
        ->and($encoded)->not->toContain('token-secret')
        ->and($encoded)->not->toContain('access_token')
        ->and($encoded)->not->toContain('secret-value');
});

it('classifies disconnect credential review permission and capability drift explicitly', function () {
    [$disconnectFixture, $disconnectAuthorization] = task0040RetryOutcomeAuthorization('outcome-disconnected');
    DB::table('provider_connections')
        ->where('id', $disconnectFixture['providerConnectionId'])
        ->update(['readiness_status' => 'unavailable']);
    $disconnected = app(PublicationProviderOutcomeService::class)->assess(
        $disconnectAuthorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        null,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    [$credentialFixture, $credentialAuthorization] = task0040RetryOutcomeAuthorization('outcome-credential');
    DB::table('provider_connections')
        ->where('id', $credentialFixture['providerConnectionId'])
        ->update(['token_expires_at' => new DateTimeImmutable('2026-07-15T13:32:00+00:00')]);
    $credential = app(PublicationProviderOutcomeService::class)->assess(
        $credentialAuthorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        null,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    [$reviewFixture, $reviewAuthorization] = task0040RetryOutcomeAuthorization('outcome-review');
    DB::table('provider_connections')
        ->where('id', $reviewFixture['providerConnectionId'])
        ->update(['provider_review_status' => 'pending_review']);
    $review = app(PublicationProviderOutcomeService::class)->assess(
        $reviewAuthorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        null,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    [$permissionFixture, $permissionAuthorization] = task0040RetryOutcomeAuthorization('outcome-permission');
    DB::table('provider_connections')
        ->where('id', $permissionFixture['providerConnectionId'])
        ->update(['granted_scopes' => json_encode([], JSON_THROW_ON_ERROR)]);
    $permission = app(PublicationProviderOutcomeService::class)->assess(
        $permissionAuthorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        null,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    [$capabilityFixture, $capabilityAuthorization] = task0040RetryOutcomeAuthorization('outcome-capability');
    Task0040PublicationOperationFixture::addCapability(
        $capabilityFixture,
        'publication.create',
        'newer-create',
        observedAt: '2026-07-15T13:32:10+00:00',
    );
    $capability = app(PublicationProviderOutcomeService::class)->assess(
        $capabilityAuthorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        null,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    expect($disconnected->code)->toBe(PublicationProviderOutcomeCode::ProviderDisconnected)
        ->and($credential->code)->toBe(PublicationProviderOutcomeCode::CredentialInvalid)
        ->and($review->code)->toBe(PublicationProviderOutcomeCode::AppReviewRestricted)
        ->and($permission->code)->toBe(PublicationProviderOutcomeCode::PermissionLost)
        ->and($capability->code)->toBe(PublicationProviderOutcomeCode::CapabilityVersionDrift);
});

it('classifies circuit and rate-limit state deterministically without fallback', function () {
    [, $authorization] = task0040RetryOutcomeAuthorization('outcome-circuit-rate');

    $rateError = new NormalizedProviderError(
        category: ProviderErrorCategory::RateLimited,
        message: 'quota window exhausted',
        httpStatus: 429,
        retryAfterSeconds: 45,
    );

    $open = app(PublicationProviderOutcomeService::class)->assess(
        $authorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Open,
        $rateError,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );
    $halfOpen = app(PublicationProviderOutcomeService::class)->assess(
        $authorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::HalfOpen,
        $rateError,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );
    $rateLimited = app(PublicationProviderOutcomeService::class)->assess(
        $authorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        $rateError,
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    expect($open->code)->toBe(PublicationProviderOutcomeCode::CircuitOpen)
        ->and($open->retryEligible)->toBeFalse()
        ->and($halfOpen->code)->toBe(PublicationProviderOutcomeCode::CircuitHalfOpen)
        ->and($halfOpen->retryEligible)->toBeFalse()
        ->and($rateLimited->code)->toBe(PublicationProviderOutcomeCode::RateLimited)
        ->and($rateLimited->retryEligible)->toBeTrue()
        ->and($rateLimited->retryAfterSeconds)->toBe(45)
        ->and($rateLimited->fallbackAllowed)->toBeFalse();
});

it('normalizes provider error classes without leaking raw provider evidence', function () {
    [, $authorization] = task0040RetryOutcomeAuthorization('outcome-errors');
    $service = app(PublicationProviderOutcomeService::class);
    $cases = [
        [ProviderErrorCategory::Authentication, PublicationProviderOutcomeCode::CredentialInvalid],
        [ProviderErrorCategory::Authorization, PublicationProviderOutcomeCode::PermissionLost],
        [ProviderErrorCategory::Unavailable, PublicationProviderOutcomeCode::ProviderUnavailable],
        [ProviderErrorCategory::Retryable, PublicationProviderOutcomeCode::ProviderRetryable],
        [ProviderErrorCategory::Validation, PublicationProviderOutcomeCode::ProviderRejected],
        [ProviderErrorCategory::Permanent, PublicationProviderOutcomeCode::ProviderRejected],
        [ProviderErrorCategory::Unknown, PublicationProviderOutcomeCode::ProviderUnknown],
    ];

    foreach ($cases as [$category, $expected]) {
        $outcome = $service->assess(
            $authorization,
            task0040ProviderOutcomeBoundaries(),
            PublicationProviderCircuitState::Closed,
            new NormalizedProviderError(
                category: $category,
                message: 'raw-provider-message-secret',
                providerCode: 'raw-code',
                evidence: ['credential' => 'secret'],
            ),
            new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
        );

        expect($outcome->code)->toBe($expected)
            ->and(json_encode($outcome->canonicalPayload(), JSON_THROW_ON_ERROR))
            ->not->toContain('raw-provider-message-secret')
            ->not->toContain('"credential"');
    }
});

it('does not mutate canonical attempt or provider-status history while assessing outcomes', function () {
    [, $authorization] = task0040RetryOutcomeAuthorization('outcome-immutable');

    $attemptBefore = DB::table('publication_attempts')->where('id', $authorization->publicationAttemptId)->first();
    $projectionBefore = DB::table('publication_status_projections')
        ->where('publication_attempt_id', $authorization->publicationAttemptId)
        ->first();
    $observationsBefore = DB::table('publication_status_observations')->count();

    app(PublicationProviderOutcomeService::class)->assess(
        $authorization,
        task0040ProviderOutcomeBoundaries(),
        PublicationProviderCircuitState::Closed,
        new NormalizedProviderError(
            category: ProviderErrorCategory::RateLimited,
            message: 'limited',
            retryAfterSeconds: 30,
        ),
        new DateTimeImmutable('2026-07-15T13:32:30+00:00'),
    );

    expect(DB::table('publication_attempts')->where('id', $authorization->publicationAttemptId)->first())
        ->toEqual($attemptBefore)
        ->and(DB::table('publication_status_projections')
            ->where('publication_attempt_id', $authorization->publicationAttemptId)
            ->first())->toEqual($projectionBefore)
        ->and(DB::table('publication_status_observations')->count())->toBe($observationsBefore);
});
