<?php

namespace App\Modules\Providers\Domain\Templates;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProviderTemplateObservation
{
    public function __construct(
        public string $workspaceId,
        public string $providerId,
        public string $providerTemplateReference,
        public string $providerFingerprint,
        public string $sourceVersion,
        public DateTimeImmutable $observedAt,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'providerId' => $this->providerId,
            'providerTemplateReference' => $this->providerTemplateReference,
            'sourceVersion' => $this->sourceVersion,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Provider template observation {$field} must not be empty.");
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $this->providerFingerprint) !== 1) {
            throw new InvalidArgumentException('Provider template observation fingerprint must be a SHA-256 hex digest.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'provider_id' => $this->providerId,
            'provider_template_reference' => $this->providerTemplateReference,
            'provider_fingerprint' => strtolower($this->providerFingerprint),
            'source_version' => $this->sourceVersion,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
        ];
    }
}
