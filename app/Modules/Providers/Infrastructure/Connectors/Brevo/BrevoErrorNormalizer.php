<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Brevo;

use App\Modules\Providers\Domain\Connectors\Contracts\ProviderErrorNormalizer;
use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderFailureEvidence;

final class BrevoErrorNormalizer implements ProviderErrorNormalizer
{
    public function normalize(ProviderFailureEvidence $failure): NormalizedProviderError
    {
        $category = $this->categoryFor($failure);
        $resetSeconds = $this->integerHeader($failure->headers, 'x-sib-ratelimit-reset');
        $retryAfter = $this->integerHeader($failure->headers, 'retry-after') ?? $resetSeconds;

        return new NormalizedProviderError(
            category: $category,
            message: $failure->message,
            providerCode: $failure->providerCode,
            httpStatus: $failure->httpStatus,
            retryAfterSeconds: $retryAfter,
            evidence: [
                'headers' => $failure->headers,
                'metadata' => $failure->metadata,
            ],
        );
    }

    private function categoryFor(ProviderFailureEvidence $failure): ProviderErrorCategory
    {
        $code = strtolower((string) $failure->providerCode);
        $status = $failure->httpStatus;

        if ($status === 429) {
            return ProviderErrorCategory::RateLimited;
        }

        if ($status === 401 || $code === 'unauthorized') {
            return ProviderErrorCategory::Authentication;
        }

        if ($status === 403 || in_array($code, ['permission_denied', 'account_under_validation'], true)) {
            return ProviderErrorCategory::Authorization;
        }

        if ($status !== null && $status >= 500) {
            return ProviderErrorCategory::Unavailable;
        }

        if ($status === 400 || in_array($code, ['missing_parameter', 'out_of_range', 'duplicate_parameter'], true)) {
            return ProviderErrorCategory::Validation;
        }

        if ($code === 'duplicate_request') {
            return ProviderErrorCategory::Retryable;
        }

        if (in_array($code, ['not_enough_credits', 'document_not_found', 'method_not_allowed'], true)) {
            return ProviderErrorCategory::Permanent;
        }

        if ($status !== null && $status >= 400) {
            return ProviderErrorCategory::Permanent;
        }

        return ProviderErrorCategory::Unknown;
    }

    /** @param array<string, string|list<string>> $headers */
    private function integerHeader(array $headers, string $name): ?int
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) !== strtolower($name)) {
                continue;
            }

            $raw = is_array($value) ? ($value[0] ?? null) : $value;
            if ($raw === null || ! ctype_digit((string) $raw)) {
                return null;
            }

            return (int) $raw;
        }

        return null;
    }
}
