<?php

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderFailureEvidence;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Infrastructure\Connectors\Gmail\GmailConnector;
use App\Modules\Providers\Infrastructure\Connectors\Gmail\GmailErrorNormalizer;
use App\Modules\Providers\Infrastructure\Connectors\Gmail\GmailMessageEncoder;
use App\Modules\Providers\Infrastructure\Connectors\Gmail\GmailQuotaSignalExtractor;
use DateTimeImmutable;

it('advertises Gmail as a least-privilege mailbox connector rather than bulk marketing delivery', function () {
    $manifest = (new GmailConnector(new DateTimeImmutable('2026-09-03T03:40:00+05:00')))->manifest();
    $send = $manifest->capability('email.send');
    $webhook = $manifest->capability('webhook.verify');

    expect($manifest->connectorKey)->toBe('gmail-api')
        ->and($manifest->metadata['provider_class'])->toBe('mailbox')
        ->and($manifest->metadata['bulk_marketing_provider'])->toBeFalse()
        ->and($manifest->metadata['oauth_least_privilege_scope'])->toBe(GmailConnector::SEND_SCOPE)
        ->and($send->support)->toBe(CapabilitySupport::Supported)
        ->and($send->isUsableAt(ProviderReadinessStatus::Ready))->toBeTrue()
        ->and($send->isUsableAt(ProviderReadinessStatus::SandboxOnly))->toBeFalse()
        ->and($send->constraints['required_scope'])->toBe(GmailConnector::SEND_SCOPE)
        ->and($send->constraints['bulk_marketing'])->toBeFalse()
        ->and($send->constraints['message_format'])->toBe('rfc2822-mime-base64url')
        ->and($webhook->support)->toBe(CapabilitySupport::Unsupported);
});

it('encodes RFC2822 MIME content as unpadded base64url for Gmail messages.send', function () {
    $encoded = (new GmailMessageEncoder)->encodeRawMime("Subject: Test\r\n\r\nHello");

    expect($encoded)->toBe('U3ViamVjdDogVGVzdA0KDQpIZWxsbw')
        ->and($encoded)->not->toContain('+')
        ->and($encoded)->not->toContain('/')
        ->and($encoded)->not->toContain('=');
});

it('preserves Gmail project and user quota-unit provenance without a global hard-coded quota', function () {
    $signals = (new GmailQuotaSignalExtractor)->extract(new ProviderResponseEvidence(
        httpStatus: 200,
        providerRequestId: 'gmail-request-42',
        metadata: [
            'observed_at' => '2026-09-03T00:00:00+00:00',
            'quota_signals' => [
                [
                    'scope_type' => 'project',
                    'scope_reference' => 'project-123',
                    'window_type' => 'minute',
                    'window_seconds' => 60,
                    'source_key' => 'google-cloud-quota:project',
                    'limit_value' => 1200000,
                    'remaining_value' => 1199500,
                    'resets_at' => '2026-09-03T00:01:00+00:00',
                    'project_cohort' => 'post-2026-05-01',
                ],
                [
                    'scope_type' => 'user',
                    'scope_reference' => 'user-42',
                    'window_type' => 'minute',
                    'window_seconds' => 60,
                    'source_key' => 'google-cloud-quota:user',
                    'limit_value' => 15000,
                    'remaining_value' => 14900,
                    'resets_at' => '2026-09-03T00:01:00+00:00',
                    'project_cohort' => 'post-2026-05-01',
                ],
            ],
        ],
    ), 'email.send');

    expect($signals)->toHaveCount(2)
        ->and($signals[0]->scopeType)->toBe('project')
        ->and($signals[0]->scopeReference)->toBe('project-123')
        ->and($signals[0]->unit)->toBe('quota_unit')
        ->and($signals[0]->accountTier)->toBe('post-2026-05-01')
        ->and($signals[0]->evidence['quota_model_changed_at'])->toBe('2026-05-01')
        ->and($signals[1]->scopeType)->toBe('user')
        ->and($signals[1]->principalType)->toBe('user')
        ->and($signals[1]->principalReference)->toBe('user-42')
        ->and($signals[1]->scopeReference)->toBeNull();
});

it('normalizes Gmail quota scope auth validation and availability failures', function () {
    $normalizer = new GmailErrorNormalizer;

    $limited = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'User rate limit exceeded',
        providerCode: 'userRateLimitExceeded',
        httpStatus: 403,
        headers: ['Retry-After' => '20'],
    ));
    $scope = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Insufficient permission',
        providerCode: 'insufficientPermissions',
        httpStatus: 403,
    ));
    $auth = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Invalid credentials',
        providerCode: 'authError',
        httpStatus: 401,
    ));
    $validation = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Bad MIME payload',
        providerCode: 'invalidArgument',
        httpStatus: 400,
    ));
    $unavailable = $normalizer->normalize(new ProviderFailureEvidence(
        message: 'Backend error',
        httpStatus: 503,
    ));

    expect($limited->category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($limited->retryAfterSeconds)->toBe(20)
        ->and($scope->category)->toBe(ProviderErrorCategory::Authorization)
        ->and($auth->category)->toBe(ProviderErrorCategory::Authentication)
        ->and($validation->category)->toBe(ProviderErrorCategory::Validation)
        ->and($unavailable->category)->toBe(ProviderErrorCategory::Unavailable);
});

it('does not invent Gmail quota evidence when current project/user quota data is unavailable', function () {
    expect((new GmailQuotaSignalExtractor)->extract(
        new ProviderResponseEvidence(httpStatus: 200),
        'email.send',
    ))->toBe([]);
});
