<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Brevo;

use App\Modules\Providers\Domain\Connectors\Contracts\QuotaSignalExtractor;
use App\Modules\Providers\Domain\Connectors\ProviderQuotaSignal;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;
use DateTimeImmutable;
use Exception;

final class BrevoQuotaSignalExtractor implements QuotaSignalExtractor
{
    public function extract(ProviderResponseEvidence $response, string $operation): array
    {
        $limit = $this->header($response->headers, 'x-sib-ratelimit-limit');
        $remaining = $this->header($response->headers, 'x-sib-ratelimit-remaining');
        $resetSeconds = $this->integerHeader($response->headers, 'x-sib-ratelimit-reset');

        if ($limit === null && $remaining === null && $resetSeconds === null) {
            return [];
        }

        $observedAt = $this->date($response->metadata['observed_at'] ?? null);
        $resetsAt = null;
        if ($observedAt !== null && $resetSeconds !== null) {
            $candidate = $observedAt->modify('+'.$resetSeconds.' seconds');
            $resetsAt = $candidate === false ? null : $candidate;
        }
        $windowSeconds = $response->metadata['rate_limit_window_seconds'] ?? null;
        if (! is_int($windowSeconds) || $windowSeconds <= 0) {
            $windowSeconds = null;
        }

        return [
            new ProviderQuotaSignal(
                operation: $operation,
                scopeType: 'endpoint',
                unit: 'request',
                windowType: $this->metadataString($response, 'rate_limit_window') ?? 'provider-defined',
                sourceKey: 'response-header:x-sib-ratelimit-*',
                scopeReference: $this->metadataString($response, 'endpoint'),
                windowSeconds: $windowSeconds,
                accountTier: $this->metadataString($response, 'account_tier'),
                limitValue: $limit,
                remainingValue: $remaining,
                resetsAt: $resetsAt,
                evidence: [
                    'reset_seconds' => $resetSeconds,
                    'provider_request_id' => $response->providerRequestId,
                    'http_status' => $response->httpStatus,
                    'observed_at' => $response->metadata['observed_at'] ?? null,
                ],
            ),
        ];
    }

    /** @param array<string, string|list<string>> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) !== strtolower($name)) {
                continue;
            }

            $raw = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($raw) && $raw !== '' ? $raw : null;
        }

        return null;
    }

    /** @param array<string, string|list<string>> $headers */
    private function integerHeader(array $headers, string $name): ?int
    {
        $raw = $this->header($headers, $name);

        return $raw !== null && ctype_digit($raw) ? (int) $raw : null;
    }

    private function metadataString(ProviderResponseEvidence $response, string $key): ?string
    {
        $value = $response->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
