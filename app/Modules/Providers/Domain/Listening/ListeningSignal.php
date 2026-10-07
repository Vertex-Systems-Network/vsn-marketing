<?php

namespace App\Modules\Providers\Domain\Listening;

use InvalidArgumentException;

final readonly class ListeningSignal
{
    public function __construct(
        public string $tenantId,
        public string $providerKey,
        public string $externalId,
        public ListeningSourceType $sourceType,
        public string $scope,
        public int $retentionDays,
        public int $rateLimitPerMinute,
        public string $provenanceUrl,
        public bool $coverageComplete = false,
    ) {
        foreach ([$this->tenantId, $this->providerKey, $this->externalId, $this->scope] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Listening signals require tenant, provider, identity and scope.');
            }
        }
        if ($this->retentionDays < 1 || $this->rateLimitPerMinute < 1) {
            throw new InvalidArgumentException('Retention and rate limits must be positive.');
        }
        if (! str_starts_with($this->provenanceUrl, 'https://')) {
            throw new InvalidArgumentException('Listening provenance must be HTTPS.');
        }
    }

    public function isCoverageKnown(): bool
    {
        return $this->coverageComplete;
    }
}
