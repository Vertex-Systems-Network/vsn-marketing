<?php

declare(strict_types=1);

namespace App\Connectors\ErrorNormalizer;

use App\Connectors\Contracts\ErrorNormalizerInterface;
use App\Connectors\Enums\ErrorCategory;

/**
 * Stripe-specific error normalizer: maps Stripe API error shapes into normalized ErrorCategory.
 * Reference shapes: https://stripe.com/docs/api/errors
 */
class StripeErrorNormalizer implements ErrorNormalizerInterface
{
    public function normalize(mixed $providerError): array
    {
        $details = [];
        $retryAfter = null;

        // Stripe errors are often arrays with 'error' => ['type' => ..., 'code' => ..., 'message' => ...]
        if (is_array($providerError) && isset($providerError['error']) && is_array($providerError['error'])) {
            $err = $providerError['error'];
            $details = $err;

            $type = isset($err['type']) ? strtolower((string)$err['type']) : null;
            $code = isset($err['code']) ? strtolower((string)$err['code']) : null;

            // Rate limit
            if ($type === 'rate_limit' || $code === 'rate_limit') {
                $category = ErrorCategory::RATE_LIMITED;
                if (!empty($providerError['headers']['Retry-After'])) {
                    $retryAfter = (int)$providerError['headers']['Retry-After'];
                }
                return ['category' => $category, 'retry_after' => $retryAfter, 'details' => $details];
            }

            // Authentication
            if ($type === 'invalid_request_error' && $code === 'authentication_required') {
                return ['category' => ErrorCategory::AUTHENTICATION, 'retry_after' => null, 'details' => $details];
            }

            if ($type === 'invalid_request_error' && in_array($code, ['parameter_missing','invalid_parameter','invalid_request'])) {
                return ['category' => ErrorCategory::VALIDATION, 'retry_after' => null, 'details' => $details];
            }

            if ($type === 'api_error') {
                return ['category' => ErrorCategory::UNAVAILABLE, 'retry_after' => $retryAfter, 'details' => $details];
            }

            if ($type === 'card_error') {
                // Cards failures are generally permanent or require user action
                return ['category' => ErrorCategory::PERMANENT, 'retry_after' => null, 'details' => $details];
            }

            // Authorization-like
            if ($code === 'insufficient_funds' || $code === 'card_declined') {
                return ['category' => ErrorCategory::AUTHORIZATION, 'retry_after' => null, 'details' => $details];
            }

            // Fallback
            return ['category' => ErrorCategory::PERMANENT, 'retry_after' => null, 'details' => $details];
        }

        // If exception-like
        if ($providerError instanceof \Throwable) {
            $details['message'] = $providerError->getMessage();
            $details['class'] = get_class($providerError);
            return ['category' => ErrorCategory::PERMANENT, 'retry_after' => null, 'details' => $details];
        }

        // Fallback
        return ['category' => ErrorCategory::PERMANENT, 'retry_after' => null, 'details' => ['raw' => $providerError]];
    }
}
