<?php

namespace App\Modules\Providers\Domain\Community;

use InvalidArgumentException;

final readonly class CommunityItem
{
    public function __construct(
        public string $tenantId,
        public string $providerKey,
        public string $externalId,
        public CommunityItemType $type,
        public string $authorExternalId,
        public string $body,
        public string $provenanceUrl,
    ) {
        foreach ([$this->tenantId, $this->providerKey, $this->externalId, $this->authorExternalId, $this->body] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Community items require non-empty identity and body fields.');
            }
        }
        if (! str_starts_with($this->provenanceUrl, 'https://')
            || filter_var($this->provenanceUrl, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f]/', $this->provenanceUrl) === 1) {
            throw new InvalidArgumentException('Community provenance must be a valid HTTPS URL.');
        }
    }
}
