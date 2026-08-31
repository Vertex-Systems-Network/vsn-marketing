<?php

declare(strict_types=1);

namespace App\Connectors\ErrorNormalizer;

use App\Connectors\Contracts\ErrorNormalizerInterface;
use App\Connectors\Enums\ErrorCategory;

/**
 * Basic provider-agnostic error normalizer.
 * Maps common shapes into normalized categories while preserving provider evidence.
 */
class BaseErrorNormalizer implements ErrorNormalizerInterface
{
    public function normalize(mixed $providerError): array
    {
        $details = [];
        $retryAfter = null;

        // If it's an exception
        if ($providerError instanceof \Throwable) {
            $details['message'] = $providerError->getMessage();
            $details['class'] = get_class($providerError);

            // Try to detect HTTP-like exceptions
            if (method_exists($providerError, 'getStatusCode')) {
                $status = (int) $providerError->getStatusCode();
                if ($status === 401) {
                    $category = ErrorCategory::AUTHENTICATION;
                } elseif ($status === 403) {
                    $category = ErrorCategory::AUTHORIZATION;
                } elseif ($status === 429) {
                    $category = ErrorCategory::RATE_LIMITED;
                    if (method_exists($providerError, 'getHeaders')) {
                        $h = $providerError->getHeaders();
                        if (!empty($h['Retry-After'][0])) {
                            $retryAfter = (int) $h['Retry-After'][0];
                        }
                    }
                } elseif ($status >= 500) {
                    $category = ErrorCategory::UNAVAILABLE;
                } else {
                    $category = ErrorCategory::PERMANENT;
                }
            } else {
                $category = ErrorCategory::PERMANENT;
            }

            return [
                'category' => $category,
                'retry_after' => $retryAfter,
                'details' => $details,
            ];
        }

        // If it's an array / provider payload
        if (is_array($providerError)) {
            $details = $providerError;

            // Common provider keys
            if (!empty($providerError['error']['type'])) {
                $t = strtolower($providerError['error']['type']);
                if (str_contains($t, 'auth')) {
                    $category = ErrorCategory::AUTHENTICATION;
                } elseif (str_contains($t, 'rate') || !empty($providerError['error']['code']) && (string)$providerError['error']['code'] === 'rate_limit') {
                    $category = ErrorCategory::RATE_LIMITED;
                } elseif (str_contains($t, 'validation') || str_contains($t, 'invalid')) {
                    $category = ErrorCategory::VALIDATION;
                } else {
                    $category = ErrorCategory::PERMANENT;
                }
            } elseif (!empty($providerError['status']) && is_int($providerError['status'])) {
                $status = $providerError['status'];
                if ($status === 401) {
                    $category = ErrorCategory::AUTHENTICATION;
                } elseif ($status === 403) {
                    $category = ErrorCategory::AUTHORIZATION;
                } elseif ($status === 429) {
                    $category = ErrorCategory::RATE_LIMITED;
                } elseif ($status >= 500) {
                    $category = ErrorCategory::UNAVAILABLE;
                } else {
                    $category = ErrorCategory::PERMANENT;
                }
            } else {
                $category = ErrorCategory::PERMANENT;
            }

            return [
                'category' => $category,
                'retry_after' => $retryAfter,
                'details' => $details,
            ];
        }

        // Fallback
        return [
            'category' => ErrorCategory::PERMANENT,
            'retry_after' => null,
            'details' => ['raw' => $providerError],
        ];
    }
}
