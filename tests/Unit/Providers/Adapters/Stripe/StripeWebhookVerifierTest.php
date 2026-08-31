<?php

use App\Modules\Providers\Adapters\Stripe\StripeWebhookVerifier;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use DateTimeImmutable;

it('verifies a stripe-like webhook when stripe-signature header is present', function () {
    $verifier = new StripeWebhookVerifier('whsec_test_secret');
    $request = new WebhookRequest(
        rawBody: json_encode(['id' => 'evt_test_123']),
        headers: ['stripe-signature' => 't=123,v1=signature'],
        query: [],
        receivedAt: new DateTimeImmutable(),
    );

    $result = $verifier->verify($request);

    expect($result->status->name)->toBe('Verified')
        ->and($result->sourceEventId)->toBe('evt_test_123');
});
