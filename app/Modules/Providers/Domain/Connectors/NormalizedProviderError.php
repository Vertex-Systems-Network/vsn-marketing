<?php

namespace App\Modules\Providers\Domain\Connectors;

use DateTimeImmutable;

final readonly class NormalizedProviderError
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public ProviderErrorCategory $category,
        public string $message,
        public ?string $providerCode = null,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
        public ?DateTimeImmutable $resetAt = null,
        public array $evidence = [],
    ) {}

    public function isRetryable(): bool
    {
        return in_array($this->category, [
            ProviderErrorCategory::Retryable,
            ProviderErrorCategory::RateLimited,
            ProviderErrorCategory::Unavailable,
        ], true);
    }
}
