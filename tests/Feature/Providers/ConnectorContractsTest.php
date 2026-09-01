<?php

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ConnectorCapability;
use App\Modules\Providers\Domain\Connectors\ConnectorManifest;
use App\Modules\Providers\Domain\Connectors\Contracts\WebhookReplayGuard;
use App\Modules\Providers\Domain\Connectors\Contracts\WebhookVerifier;
use App\Modules\Providers\Domain\Connectors\DefaultProviderOperationReconciler;
use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderOperation;
use App\Modules\Providers\Domain\Connectors\ProviderOperationObservation;
use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ProviderQuotaSignal;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Providers\Domain\Connectors\WebhookIngressGuard;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationResult;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationStatus;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

it('fails closed for unknown or readiness-incompatible connector capabilities', function () {
    $manifest = new ConnectorManifest(
        connectorKey: 'fixture-mail',
        connectorVersion: '1.0.0',
        apiVersionStrategy: 'explicit-version-header',
        documentationUrl: 'https://example.test/docs',
        observedAt: new DateTimeImmutable('2026-08-31T15:56:00+00:00'),
        sourceVersion: '2026-08-31',
        deprecatedAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        sunsetAt: new DateTimeImmutable('2027-06-01T00:00:00+00:00'),
        sandboxLimitations: ['outbound delivery restricted'],
        capabilities: [
            new ConnectorCapability(
                operation: 'email.send',
                support: CapabilitySupport::Supported,
                readinessStates: [ProviderReadinessStatus::Ready],
            ),
        ],
    );

    expect($manifest->capability('email.send')->isUsableAt(ProviderReadinessStatus::Ready))->toBeTrue()
        ->and($manifest->capability('email.send')->isUsableAt(ProviderReadinessStatus::SandboxOnly))->toBeFalse()
        ->and($manifest->capability('email.unknown')->support)->toBe(CapabilitySupport::Unknown)
        ->and($manifest->capability('email.unknown')->isUsableAt(ProviderReadinessStatus::Ready))->toBeFalse()
        ->and($manifest->sandboxLimitations)->toContain('outbound delivery restricted');
});

it('normalizes retry behavior without discarding provider-specific error evidence', function () {
    $limited = new NormalizedProviderError(
        category: ProviderErrorCategory::RateLimited,
        message: 'quota window exhausted',
        providerCode: 'provider_rate_code',
        httpStatus: 429,
        retryAfterSeconds: 45,
        evidence: ['request_id' => 'req-42'],
    );
    $unknown = new NormalizedProviderError(
        category: ProviderErrorCategory::Unknown,
        message: 'unclassified provider failure',
        evidence: ['raw_category' => 'future_error'],
    );

    expect($limited->isRetryable())->toBeTrue()
        ->and($limited->evidence['request_id'])->toBe('req-42')
        ->and($unknown->isRetryable())->toBeFalse()
        ->and($unknown->evidence['raw_category'])->toBe('future_error');
});

it('represents simultaneous dynamic quota signals across different scopes and windows', function () {
    $signals = [
        new ProviderQuotaSignal(
            operation: 'email.send',
            scopeType: 'account',
            unit: 'request',
            windowType: 'second',
            sourceKey: 'response-header:rate-second',
            remainingValue: '7',
        ),
        new ProviderQuotaSignal(
            operation: 'email.send',
            scopeType: 'principal',
            unit: 'quota_unit',
            windowType: 'minute',
            sourceKey: 'response-header:rate-minute',
            principalType: 'user',
            principalReference: 'user-42',
            remainingValue: '14000',
        ),
    ];

    expect($signals)->toHaveCount(2)
        ->and($signals[0]->windowType)->toBe('second')
        ->and($signals[1]->scopeType)->toBe('principal')
        ->and($signals[1]->unit)->toBe('quota_unit');
});

it('preserves raw webhook bytes and fails closed on unsupported, malformed, or duplicate delivery', function () {
    $request = new WebhookRequest(
        rawBody: '{"signed":"bytes\\nunchanged"}',
        headers: ['authorization' => 'Bearer fixture'],
        query: [],
        receivedAt: new DateTimeImmutable('2026-08-31T15:56:00+00:00'),
    );

    $unsupportedVerifier = new class implements WebhookVerifier
    {
        public function verify(WebhookRequest $request): WebhookVerificationResult
        {
            return new WebhookVerificationResult(WebhookVerificationStatus::Unsupported, 'unsupported');
        }
    };
    $replays = new class implements WebhookReplayGuard
    {
        /** @var array<string, true> */
        private array $seen = [];

        public function claim(string $workspaceId, string $connectorKey, string $deduplicationKey, DateTimeImmutable $receivedAt): bool
        {
            $key = $workspaceId.'|'.$connectorKey.'|'.$deduplicationKey;
            if (isset($this->seen[$key])) {
                return false;
            }
            $this->seen[$key] = true;

            return true;
        }
    };

    expect($request->rawBody)->toBe('{"signed":"bytes\\nunchanged"}')
        ->and(fn () => (new WebhookIngressGuard($unsupportedVerifier, $replays))->verifyAndClaim('workspace-1', 'fixture', $request))
        ->toThrow(UnexpectedValueException::class, 'Webhook authenticity verification failed closed.');

    $malformedVerifier = new class implements WebhookVerifier
    {
        public function verify(WebhookRequest $request): WebhookVerificationResult
        {
            return new WebhookVerificationResult(
                status: WebhookVerificationStatus::Verified,
                strategy: 'jwt',
            );
        }
    };

    expect(fn () => (new WebhookIngressGuard($malformedVerifier, $replays))->verifyAndClaim('workspace-1', 'fixture', $request))
        ->toThrow(UnexpectedValueException::class, 'Webhook replay protection requires a deduplication key.');

    $verified = new class implements WebhookVerifier
    {
        public function verify(WebhookRequest $request): WebhookVerificationResult
        {
            return new WebhookVerificationResult(
                status: WebhookVerificationStatus::Verified,
                strategy: 'jwt',
                deduplicationKey: 'delivery-42',
                sourceEventId: 'event-42',
            );
        }
    };
    $guard = new WebhookIngressGuard($verified, $replays);

    expect($guard->verifyAndClaim('workspace-1', 'fixture', $request)->sourceEventId)->toBe('event-42')
        ->and(fn () => $guard->verifyAndClaim('workspace-1', 'fixture', $request))
        ->toThrow(UnexpectedValueException::class, 'Webhook replay or duplicate delivery detected.');
});

it('treats transport acceptance as non-terminal and reconciles async operations monotonically and idempotently', function () {
    $submittedAt = new DateTimeImmutable('2026-08-31T15:56:00+00:00');
    $operation = new ProviderOperation(
        operation: 'messages.submit',
        idempotencyKey: 'attempt-42',
        status: ProviderOperationStatus::Accepted,
        submittedAt: $submittedAt,
    );
    $reconciler = new DefaultProviderOperationReconciler;
    $inProgress = $reconciler->reconcile($operation, new ProviderOperationObservation(
        providerOperationId: 'provider-op-7',
        status: ProviderOperationStatus::InProgress,
        source: ReconciliationSource::Polling,
        observedAt: $submittedAt->modify('+5 seconds'),
    ));
    $regressed = $reconciler->reconcile($inProgress, new ProviderOperationObservation(
        providerOperationId: 'provider-op-7',
        status: ProviderOperationStatus::Pending,
        source: ReconciliationSource::Webhook,
        observedAt: $submittedAt->modify('+6 seconds'),
    ));
    $unknown = $reconciler->reconcile($inProgress, new ProviderOperationObservation(
        providerOperationId: 'provider-op-7',
        status: ProviderOperationStatus::Unknown,
        source: ReconciliationSource::Polling,
        observedAt: $submittedAt->modify('+7 seconds'),
    ));
    $succeeded = $reconciler->reconcile($inProgress, new ProviderOperationObservation(
        providerOperationId: 'provider-op-7',
        status: ProviderOperationStatus::Succeeded,
        source: ReconciliationSource::Webhook,
        observedAt: $submittedAt->modify('+10 seconds'),
        evidence: ['delivery_id' => 'delivery-9'],
    ));
    $duplicate = $reconciler->reconcile($succeeded, new ProviderOperationObservation(
        providerOperationId: 'provider-op-7',
        status: ProviderOperationStatus::Succeeded,
        source: ReconciliationSource::Webhook,
        observedAt: $submittedAt->modify('+11 seconds'),
    ));

    expect($operation->status->isTerminal())->toBeFalse()
        ->and($inProgress->providerOperationId)->toBe('provider-op-7')
        ->and($regressed)->toBe($inProgress)
        ->and($unknown)->toBe($inProgress)
        ->and($succeeded->status->isTerminal())->toBeTrue()
        ->and($succeeded->evidence['reconciliation_source'])->toBe('webhook')
        ->and($duplicate)->toBe($succeeded)
        ->and(fn () => $reconciler->reconcile($inProgress, new ProviderOperationObservation(
            providerOperationId: 'provider-op-other',
            status: ProviderOperationStatus::Succeeded,
            source: ReconciliationSource::Webhook,
            observedAt: $submittedAt->modify('+11 seconds'),
        )))
        ->toThrow(InvalidArgumentException::class, 'Provider operation observation does not match the canonical provider operation ID.')
        ->and(fn () => $reconciler->reconcile($succeeded, new ProviderOperationObservation(
            providerOperationId: 'provider-op-7',
            status: ProviderOperationStatus::Failed,
            source: ReconciliationSource::Polling,
            observedAt: $submittedAt->modify('+12 seconds'),
        )))
        ->toThrow(InvalidArgumentException::class, 'A terminal provider operation cannot be reconciled to a different terminal or non-terminal state.');
});
