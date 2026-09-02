<?php

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderFailureEvidence;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Infrastructure\Connectors\AmazonSes\AmazonSesConnector;
use App\Modules\Providers\Infrastructure\Connectors\AmazonSes\AmazonSesErrorNormalizer;
use App\Modules\Providers\Infrastructure\Connectors\AmazonSes\AmazonSesQuotaSignalExtractor;
use DateTimeImmutable;

it('advertises SES delivery capabilities without promoting provider behavior into core', function () {
    $manifest = (new AmazonSesConnector(new DateTimeImmutable('2026-09-03T03:40:00+05:00')))->manifest();
    $send = $manifest->capability('email.send');
    $webhook = $manifest->capability('webhook.verify');

    expect($manifest->connectorKey)->toBe('amazon-ses')
        ->and($manifest->metadata['provider_class'])->toBe('delivery')
        ->and($manifest->metadata['region_required'])->toBeTrue()
        ->and($manifest->metadata['acceptance_is_delivery'])->toBeFalse()
        ->and($send->support)->toBe(CapabilitySupport::Supported)
        ->and($send->isUsableAt(ProviderReadinessStatus::SandboxOnly))->toBeTrue()
        ->and($send->isUsableAt(ProviderReadinessStatus::Ready))->toBeTrue()
        ->and($send->constraints['region_required'])->toBeTrue()
        ->and($send->constraints['provider_idempotency_token'])->toBeFalse()
        ->and($webhook->support)->toBe(CapabilitySupport::Unsupported);
});

it('extracts region-scoped rolling and per-second SES quota signals from runtime evidence', function () {
    $signals = (new AmazonSesQuotaSignalExtractor)->extract(new ProviderResponseEvidence(
        httpStatus: 200,
        providerRequestId: 'ses-request-42',
        metadata: [
            'region' => 'eu-west-1',
            'access_tier' => 'production',
            'observed_at' => '2026-09-03T03:40:00+05:00',
            'send_quota' => [
                'max_24_hour_send' => 50000,
                'max_send_rate' => 14.5,
                'sent_last_24_hours' => 12345,
            ],
        ],
    ), 'email.send');

    expect($signals)->toHaveCount(2)
        ->and($signals[0]->operation)->toBe('email.send')
        ->and($signals[0]->windowType)->toBe('rolling-24h')
        ->and($signals[0]->unit)->toBe('recipient')
        ->and($signals[0]->region)->toBe('eu-west-1')
        ->and($signals[0]->limitValue)->toBe('50000')
        ->and($signals[0]->remainingValue)->toBe('37655')
        ->and($signals[0]->evidence['provider_request_id'])->toBe('ses-request-42')
        ->and($signals[1]->windowType)->toBe('second')
        ->and($signals[1]->limitValue)->toBe('14.5');
});

it('normalizes SES auth rate validation and availability failures while preserving evidence', function () {
    $normalizer = new AmazonSesErrorNormalizer;

    $limited = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Maximum sending rate exceeded',
        providerCode: 'ThrottlingException',
        httpStatus: 429,
        headers: ['Retry-After' => '7'],
        metadata: ['request_id' => 'req-rate'],
    ));
    $auth = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Signature does not match',
        providerCode: 'SignatureDoesNotMatch',
        httpStatus: 403,
    ));
    $rejected = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Message rejected',
        providerCode: 'MessageRejected',
        httpStatus: 400,
    ));
    $unavailable = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Service unavailable',
        httpStatus: 503,
    ));

    expect($limited->category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($limited->retryAfterSeconds)->toBe(7)
        ->and($limited->isRetryable())->toBeTrue()
        ->and($limited->evidence['metadata']['request_id'])->toBe('req-rate')
        ->and($auth->category)->toBe(ProviderErrorCategory::Authentication)
        ->and($rejected->category)->toBe(ProviderErrorCategory::Validation)
        ->and($unavailable->category)->toBe(ProviderErrorCategory::Unavailable)
        ->and($unavailable->isRetryable())->toBeTrue();
});

it('does not manufacture SES quota evidence when the provider response lacks SendQuota data', function () {
    expect((new AmazonSesQuotaSignalExtractor)->extract(
        new ProviderResponseEvidence(httpStatus: 200, metadata: ['region' => 'eu-west-1']),
        'email.send',
    ))->toBe([]);
});
