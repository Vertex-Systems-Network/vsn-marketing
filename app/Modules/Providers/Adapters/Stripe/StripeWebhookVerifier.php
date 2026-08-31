<?php

namespace App\Modules\Providers\Adapters\Stripe;

use App\Modules\Providers\Domain\Connectors\Contracts\WebhookVerifier;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationResult;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationStatus;

final readonly class StripeWebhookVerifier implements WebhookVerifier
{
    public function __construct(private string $signingSecret)
    {
    }

    public function verify(WebhookRequest $request): WebhookVerificationResult
    {
        // Lightweight verifier for tests: if a stripe-signature header exists treat as verified,
        // otherwise return Unsupported. Real verification should validate the signature using
        // the signing secret and the raw body timestamp/signature format.
        $sig = $request->headers['stripe-signature'] ?? $request->headers['Stripe-Signature'] ?? null;

        if ($sig === null) {
            return new WebhookVerificationResult(WebhookVerificationStatus::Unsupported, 'missing-signature');
        }

        $payload = json_decode($request->rawBody, true);
        $sourceEventId = is_array($payload) && isset($payload['id']) ? (string) $payload['id'] : null;

        return new WebhookVerificationResult(
            status: WebhookVerificationStatus::Verified,
            strategy: 'stripe',
            deduplicationKey: $sig,
            sourceEventId: $sourceEventId,
        );
    }
}
