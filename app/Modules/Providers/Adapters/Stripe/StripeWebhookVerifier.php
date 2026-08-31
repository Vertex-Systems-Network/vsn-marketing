<?php

namespace App\Modules\Providers\Adapters\Stripe;

use App\Modules\Providers\Domain\Connectors\Contracts\WebhookVerifier;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationResult;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationStatus;

final class StripeWebhookVerifier implements WebhookVerifier
{
    // Allowed clock skew in seconds when validating the timestamped signature.
    private int $tolerance = 300; // 5 minutes

    public function __construct(private readonly string $signingSecret)
    {
    }

    public function verify(WebhookRequest $request): WebhookVerificationResult
    {
        $sigHeader = $request->headers['stripe-signature'] ?? $request->headers['Stripe-Signature'] ?? null;

        if ($sigHeader === null) {
            return new WebhookVerificationResult(WebhookVerificationStatus::Unsupported, 'stripe', 'missing-signature');
        }

        // Parse header: expected format "t=<timestamp>,v1=<signature>[,v1=<sig2>...]"
        $parts = array_map('trim', explode(',', $sigHeader));
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = (int) substr($part, 2);
                continue;
            }

            if (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }

        if ($timestamp === null || empty($signatures)) {
            return new WebhookVerificationResult(WebhookVerificationStatus::Rejected, 'stripe', 'malformed-signature-header');
        }

        // receivedAt is strongly typed to DateTimeImmutable on the request; use it directly.
        $now = $request->receivedAt->getTimestamp();

        if (abs($now - $timestamp) > $this->tolerance) {
            return new WebhookVerificationResult(WebhookVerificationStatus::Rejected, 'stripe', 'timestamp-out-of-range');
        }

        $payload = $request->rawBody;
        $signedPayload = sprintf('%d.%s', $timestamp, $payload);
        $expected = hash_hmac('sha256', $signedPayload, $this->signingSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                // Extract a source event id from the payload if available.
                $data = json_decode($payload, true);
                $sourceEventId = is_array($data) && isset($data['id']) ? (string) $data['id'] : null;

                $dedup = sprintf('%s|%s', $sig, substr($expected, 0, 8));

                return new WebhookVerificationResult(
                    WebhookVerificationStatus::Verified,
                    'stripe',
                    null,
                    $dedup,
                    $sourceEventId,
                );
            }
        }

        return new WebhookVerificationResult(WebhookVerificationStatus::Rejected, 'stripe', 'invalid-signature');
    }
}
