<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

use App\Connectors\Enums\ErrorCategory;

interface ErrorNormalizerInterface
{
    /**
     * Map a provider-specific error payload/exception into a normalized category and metadata.
     * Return an array with keys: category (ErrorCategory), retry_after (int|null), details (array)
     *
     * @param mixed $providerError
     * @return array{category: ErrorCategory, retry_after: ?int, details: array}
     */
    public function normalize(mixed $providerError): array;
}
