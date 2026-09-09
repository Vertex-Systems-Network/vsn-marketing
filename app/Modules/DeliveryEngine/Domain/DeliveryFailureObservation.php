<?php

namespace App\Modules\DeliveryEngine\Domain;

use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryFailureObservation
{
    public function __construct(
        public ?ProviderErrorCategory $errorCategory,
        public ?int $httpStatus = null,
        public ?int $minimumDelaySeconds = null,
        public ?DateTimeImmutable $resetAt = null,
        public bool $providerAccepted = false,
        public bool $acceptanceKnownNotOccurred = false,
        public bool $requestMayHaveReachedProvider = false,
        public int $attemptNumber = 1,
        public int $maxAttempts = 3,
    ) {
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('HTTP status must be between 100 and 599.');
        }

        if ($minimumDelaySeconds !== null && $minimumDelaySeconds < 0) {
            throw new InvalidArgumentException('Minimum delay seconds must be non-negative.');
        }

        if ($attemptNumber < 1 || $maxAttempts < 1) {
            throw new InvalidArgumentException('Attempt number and maximum attempts must be positive.');
        }

        if ($providerAccepted && ($errorCategory !== null || $acceptanceKnownNotOccurred)) {
            throw new InvalidArgumentException('Accepted provider evidence cannot also describe a failed or known-not-accepted attempt.');
        }
    }

    public function retryBudgetRemains(): bool
    {
        return $this->attemptNumber < $this->maxAttempts;
    }
}
