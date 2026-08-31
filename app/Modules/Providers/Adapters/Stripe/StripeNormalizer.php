<?php

namespace App\Modules\Providers\Adapters\Stripe;

use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;

final class StripeNormalizer
{
    /** @param array<string, mixed> $error */
    public function normalize(array $error): NormalizedProviderError
    {
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? ($error['error'] ?? 'unknown');
        $http = $error['status'] ?? null;

        // Map a small set of Stripe error codes to categories; default to Unknown.
        $category = match ($code) {
            'rate_limit' => ProviderErrorCategory::RateLimited,
            'api_connection_error' => ProviderErrorCategory::Unavailable,
            'invalid_request_error' => ProviderErrorCategory::Validation,
            'authentication_error' => ProviderErrorCategory::Authentication,
            default => ProviderErrorCategory::Unknown,
        };

        return new NormalizedProviderError(
            category: $category,
            message: (string) $message,
            providerCode: $code !== null ? (string) $code : null,
            httpStatus: $http !== null ? (int) $http : null,
            evidence: ['raw' => $error],
        );
    }
}
