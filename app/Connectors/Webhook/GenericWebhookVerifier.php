<?php

declare(strict_types=1);

namespace App\Connectors\Webhook;

use App\Connectors\Contracts\WebhookVerifierInterface;

/**
 * Generic webhook verifier. Supports HMAC signatures and extracting provider ids.
 * Configuration: config('connectors.webhook_secret') should provide the shared secret when available.
 */
class GenericWebhookVerifier implements WebhookVerifierInterface
{
    public function verify(string $rawBody, array $headers): bool
    {
        // Look for common signature headers
        $signature = $this->headerValue($headers, ['x-signature', 'x-hub-signature', 'signature']);
        $secret = config('connectors.webhook_secret');

        if (empty($signature) || empty($secret)) {
            // No signature to verify against; treat as unverified
            return false;
        }

        // Signature formats: 'sha1=...' or raw hex. Support 'sha256=' too.
        if (str_contains($signature, '=')) {
            [$alg, $sig] = explode('=', $signature, 2);
        } else {
            $alg = 'sha256';
            $sig = $signature;
        }

        $alg = strtolower($alg);
        $computed = hash_hmac($alg === 'sha1' ? 'sha1' : 'sha256', $rawBody, (string)$secret);

        // Use hash_equals to mitigate timing attacks
        return hash_equals($computed, $sig);
    }

    public function deduplicationId(string $rawBody, array $headers): ?string
    {
        // Common providers include a durable id header or an id field in JSON payload
        $id = $this->headerValue($headers, ['x-webhook-id', 'x-request-id', 'x-event-id']);
        if (!empty($id)) {
            return $id;
        }

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            foreach (['id', 'event_id', 'webhook_id'] as $k) {
                if (!empty($decoded[$k])) {
                    return (string)$decoded[$k];
                }
            }
        }

        return null;
    }

    private function headerValue(array $headers, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($headers as $k => $v) {
                if (strtolower($k) === strtolower($c)) {
                    if (is_array($v)) {
                        return $v[0] ?? null;
                    }
                    return (string)$v;
                }
            }
        }

        return null;
    }
}
