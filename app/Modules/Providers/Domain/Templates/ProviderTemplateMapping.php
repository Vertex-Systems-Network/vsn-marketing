<?php

namespace App\Modules\Providers\Domain\Templates;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProviderTemplateMapping
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerId,
        public string $canonicalTemplateId,
        public string $providerTemplateReference,
        public ?string $lastSyncedCanonicalVersionId,
        public ?string $lastSyncedDerivativeIdentity,
        public ?string $lastObservedProviderFingerprint,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'providerId' => $this->providerId,
            'canonicalTemplateId' => $this->canonicalTemplateId,
            'providerTemplateReference' => $this->providerTemplateReference,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Provider template mapping {$field} must not be empty.");
            }

            if (mb_strlen($value) > 191) {
                throw new InvalidArgumentException("Provider template mapping {$field} must not exceed 191 characters.");
            }
        }

        $syncValues = [
            $this->lastSyncedCanonicalVersionId,
            $this->lastSyncedDerivativeIdentity,
            $this->lastObservedProviderFingerprint,
        ];
        $present = count(array_filter($syncValues, static fn (?string $value): bool => $value !== null));

        if ($present !== 0 && $present !== count($syncValues)) {
            throw new InvalidArgumentException('Provider template synchronized state must be complete or absent.');
        }

        if ($this->lastSyncedCanonicalVersionId !== null && trim($this->lastSyncedCanonicalVersionId) === '') {
            throw new InvalidArgumentException('Last synchronized canonical version id must be null or non-empty.');
        }

        if ($this->lastSyncedDerivativeIdentity !== null) {
            self::assertSha256($this->lastSyncedDerivativeIdentity, 'last synchronized derivative identity');
        }

        if ($this->lastObservedProviderFingerprint !== null) {
            self::assertSha256($this->lastObservedProviderFingerprint, 'last observed provider fingerprint');
        }

        if ($this->updatedAt !== null && $this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Provider template mapping updatedAt must not precede createdAt.');
        }
    }

    public function withSynchronizedState(
        string $canonicalVersionId,
        string $derivativeIdentity,
        string $providerFingerprint,
        DateTimeImmutable $at,
    ): self {
        if (trim($canonicalVersionId) === '') {
            throw new InvalidArgumentException('Synchronized canonical version id must not be empty.');
        }

        if ($at < ($this->updatedAt ?? $this->createdAt)) {
            throw new InvalidArgumentException('Provider template mapping synchronization time must not move backwards.');
        }

        self::assertSha256($derivativeIdentity, 'synchronized derivative identity');
        self::assertSha256($providerFingerprint, 'synchronized provider fingerprint');

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            providerId: $this->providerId,
            canonicalTemplateId: $this->canonicalTemplateId,
            providerTemplateReference: $this->providerTemplateReference,
            lastSyncedCanonicalVersionId: $canonicalVersionId,
            lastSyncedDerivativeIdentity: strtolower($derivativeIdentity),
            lastObservedProviderFingerprint: strtolower($providerFingerprint),
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'provider_id' => $this->providerId,
            'canonical_template_id' => $this->canonicalTemplateId,
            'provider_template_reference' => $this->providerTemplateReference,
            'last_synced_canonical_version_id' => $this->lastSyncedCanonicalVersionId,
            'last_synced_derivative_identity' => $this->lastSyncedDerivativeIdentity,
            'last_observed_provider_fingerprint' => $this->lastObservedProviderFingerprint,
        ];
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new InvalidArgumentException("Provider template mapping {$label} must be a SHA-256 hex digest.");
        }
    }
}
