<?php

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderFailureEvidence;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationStatus;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Infrastructure\Connectors\Brevo\BrevoConnector;
use App\Modules\Providers\Infrastructure\Connectors\Brevo\BrevoErrorNormalizer;
use App\Modules\Providers\Infrastructure\Connectors\Brevo\BrevoQuotaSignalExtractor;
use App\Modules\Providers\Infrastructure\Connectors\Brevo\BrevoWebhookVerifier;
use DateTimeImmutable;

it('keeps Brevo sandbox acceptance distinct from delivery evidence', function () {
    $manifest = (new BrevoConnector(new DateTimeImmutable('2026-09-03T03:40:00+05:00')))->manifest();
    $send = $manifest->capability('email.send');
    $webhook = $manifest->capability('webhook.verify');

    expect($manifest->connectorKey)->toBe('brevo')
        ->and($manifest->metadata['provider_class'])->toBe('delivery_marketing_platform')
        ->and($manifest->metadata['acceptance_is_delivery'])->toBeFalse()
        ->and($send->support)->toBe(CapabilitySupport::Supported)
        ->and($send->isUsableAt(ProviderReadinessStatus::SandboxOnly))->toBeTrue()
        ->and($send->constraints['sandbox_header'])->toBe('X-Sib-Sandbox: drop')
        ->and($send->constraints['sandbox_delivers'])->toBeFalse()
        ->and($send->constraints['sandbox_creates_email_log'])->toBeFalse()
        ->and($send->constraints['provider_idempotency_token'])->toBeFalse()
        ->and($webhook->support)->toBe(CapabilitySupport::Supported)
        ->and($webhook->constraints['universal_hmac_assumed'])->toBeFalse();
});

it('extracts Brevo runtime rate-limit headers with reset provenance', function () {
    $signals = (new BrevoQuotaSignalExtractor)->extract(new ProviderResponseEvidence(
        httpStatus: 200,
        headers: [
            'x-sib-ratelimit-limit' => '1000',
            'x-sib-ratelimit-remaining' => '750',
            'x-sib-ratelimit-reset' => '45',
        ],
        providerRequestId: 'brevo-request-42',
        metadata: [
            'endpoint' => '/v3/smtp/email',
            'account_tier' => 'professional',
            'rate_limit_window' => 'second',
            'rate_limit_window_seconds' => 1,
            'observed_at' => '2026-09-03T00:00:00+00:00',
        ],
    ), 'email.send');

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->scopeType)->toBe('endpoint')
        ->and($signals[0]->scopeReference)->toBe('/v3/smtp/email')
        ->and($signals[0]->unit)->toBe('request')
        ->and($signals[0]->limitValue)->toBe('1000')
        ->and($signals[0]->remainingValue)->toBe('750')
        ->and($signals[0]->resetsAt?->format(DATE_ATOM))->toBe('2026-09-03T00:00:45+00:00')
        ->and($signals[0]->evidence['provider_request_id'])->toBe('brevo-request-42');
});

it('normalizes Brevo rate authentication authorization validation and permanent failures', function () {
    $normalizer = new BrevoErrorNormalizer;

    $limited = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Too many requests',
        httpStatus: 429,
        headers: ['x-sib-ratelimit-reset' => '45'],
    ));
    $auth = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Unauthorized',
        providerCode: 'unauthorized',
        httpStatus: 401,
    ));
    $authorization = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Account under validation',
        providerCode: 'account_under_validation',
        httpStatus: 403,
    ));
    $validation = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Missing parameter',
        providerCode: 'missing_parameter',
        httpStatus: 400,
    ));
    $credits = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Not enough credits',
        providerCode: 'not_enough_credits',
        httpStatus: 402,
    ));

    expect($limited->category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($limited->retryAfterSeconds)->toBe(45)
        ->and($auth->category)->toBe(ProviderErrorCategory::Authentication)
        ->and($authorization->category)->toBe(ProviderErrorCategory::Authorization)
        ->and($validation->category)->toBe(ProviderErrorCategory::Validation)
        ->and($credits->category)->toBe(ProviderErrorCategory::Permanent);
});

it('verifies Brevo bearer and source-address strategies without assuming a signature scheme', function () {
    $receivedAt = new DateTimeImmutable('2026-09-03T00:00:00+00:00');
    $payload = '{"event":"delivered","id":26224,"message-id":"message-42"}';
    $bearer = new BrevoWebhookVerifier('bearer', ['token' => 'fixture-bearer-token']);

    $verified = $bearer->verify(new WebhookRequest(
        rawBody: $payload,
        headers: ['Authorization' => 'Bearer fixture-bearer-token'],
        query: [],
        receivedAt: $receivedAt,
    ));
    $rejected = $bearer->verify(new WebhookRequest(
        rawBody: $payload,
        headers: ['Authorization' => 'Bearer wrong-token'],
        query: [],
        receivedAt: $receivedAt,
    ));
    $cidrVerified = (new BrevoWebhookVerifier('source_ip', [
        'allowed_source_addresses' => ['192.0.2.0/24'],
    ]))->verify(new WebhookRequest(
        rawBody: $payload,
        headers: [],
        query: [],
        receivedAt: $receivedAt,
        sourceAddress: '192.0.2.42',
    ));

    expect($verified->status)->toBe(WebhookVerificationStatus::Verified)
        ->and($verified->sourceEventId)->toBe('26224')
        ->and($verified->deduplicationKey)->toBe(hash('sha256', $payload))
        ->and($rejected->status)->toBe(WebhookVerificationStatus::Rejected)
        ->and($cidrVerified->status)->toBe(WebhookVerificationStatus::Verified);
});

it('rejects malformed Brevo webhook payloads after authentication succeeds', function () {
    $result = (new BrevoWebhookVerifier('custom_headers', [
        'headers' => ['client-id' => 'fixture-client-id'],
    ]))->verify(new WebhookRequest(
        rawBody: '{not-json',
        headers: ['client-id' => 'fixture-client-id'],
        query: [],
        receivedAt: new DateTimeImmutable('2026-09-03T00:00:00+00:00'),
    ));

    expect($result->status)->toBe(WebhookVerificationStatus::Rejected)
        ->and($result->reason)->toBe('Brevo webhook body is not valid JSON.');
});
