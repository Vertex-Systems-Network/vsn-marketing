<?php

use App\Modules\Providers\Adapters\Stripe\StripeWebhookVerifier;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use DateTimeImmutable;

it('verifies a stripe-like webhook when stripe-signature header is valid', function () {
    $secret = 'whsec_test_secret';
    $payload = json_encode(['id' => 'evt_test_123']);
    $ts = time();
    $sig = hash_hmac('sha256', sprintf('%d.%s', $ts, $payload), $secret);
    $header = sprintf('t=%d,v1=%s', $ts, $sig);

    $verifier = new StripeWebhookVerifier($secret);
    $request = new WebhookRequest(
        rawBody: $payload,
        headers: ['stripe-signature' => $header],
        query: [],
        receivedAt: new DateTimeImmutable("@{$ts}"),
    );

    $result = $verifier->verify($request);

    expect($result->status->name)->toBe('Verified')
        ->and($result->sourceEventId)->toBe('evt_test_123');
});

it('rejects a stripe webhook with an invalid signature', function () {
    $secret = 'whsec_test_secret';
    $payload = json_encode(['id' => 'evt_test_456']);
    $ts = time();
    // wrong secret used to produce signature
    $badSig = hash_hmac('sha256', sprintf('%d.%s', $ts, $payload), 'wrong_secret');
    $header = sprintf('t=%d,v1=%s', $ts, $badSig);

    $verifier = new StripeWebhookVerifier($secret);
    $request = new WebhookRequest(
        rawBody: $payload,
        headers: ['stripe-signature' => $header],
        query: [],
        receivedAt: new DateTimeImmutable("@{$ts}"),
    );

    $result = $verifier->verify($request);

    expect($result->status->name)->toBe('Rejected')
        ->and($result->reason)->toBe('invalid-signature');
});

it('returns Unsupported when stripe-signature header is missing', function () {
    $secret = 'whsec_test_secret';
    $payload = json_encode(['id' => 'evt_test_789']);

    $verifier = new StripeWebhookVerifier($secret);
    $request = new WebhookRequest(
        rawBody: $payload,
        headers: [],
        query: [],
        receivedAt: new DateTimeImmutable(),
    );

    $result = $verifier->verify($request);

    expect($result->status->name)->toBe('Unsupported')
        ->and($result->reason)->toBe('missing-signature');
});
