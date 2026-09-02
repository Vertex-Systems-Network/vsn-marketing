<?php

namespace App\Modules\Providers\Infrastructure\Connectors\AmazonSes;

use App\Modules\Providers\Domain\Connectors\Contracts\ProviderErrorNormalizer;
use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Connectors\ProviderFailureEvidence;

final class AmazonSesErrorNormalizer implements ProviderErrorNormalizer
{
    public function normalize(ProviderFailureEvidence $failure): NormalizedProviderError
    {
        $category = $this->categoryFor($failure);
        $retryAfter = $this->integerHeader($failure->headers, 'retry-after');

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

        if ($status === 429 || in_array($code, ['throttlingexception', 'toomanyrequestsexception'], true)) {
            return ProviderErrorCategory::RateLimited;
        }

        if ($status === 401 || in_array($code, ['invalidclienttokenid', 'signaturedoesnotmatch', 'unrecognizedclientexception'], true)) {
            return ProviderErrorCategory::Authentication;
        }

        if ($status === 403 || in_array($code, ['accessdenied', 'accessdeniedexception'], true)) {
            return ProviderErrorCategory::Authorization;
        }

        if ($status !== null && $status >= 500) {
            return ProviderErrorCategory::Unavailable;
        }

        if ($status === 400 || in_array($code, ['messagerejected', 'mailfromdomainnotverifiedexception'], true)) {
            return ProviderErrorCategory::Validation;
        }

        if ($status !== null && $status >= 400 && $status < 500) {
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
