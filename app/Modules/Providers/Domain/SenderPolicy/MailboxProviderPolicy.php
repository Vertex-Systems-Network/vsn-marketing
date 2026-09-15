<?php

namespace App\Modules\Providers\Domain\SenderPolicy;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MailboxProviderPolicy
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerKey,
        public string $policyKey,
        public string $policyVersion,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public ?int $highVolumeThreshold,
        public ?string $thresholdUnit,
        public array $classificationInputs,
        public array $requirements,
        public string $provenanceUrl,
        public ?string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'providerKey' => $this->providerKey,
            'policyKey' => $this->policyKey,
            'policyVersion' => $this->policyVersion,
            'provenanceUrl' => $this->provenanceUrl,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name.' must not be empty.');
            }
        }

        if ($this->effectiveUntil !== null && $this->effectiveUntil <= $this->effectiveFrom) {
            throw new InvalidArgumentException('Provider policy effectiveUntil must be later than effectiveFrom.');
        }

        if ($this->highVolumeThreshold !== null && $this->highVolumeThreshold < 1) {
            throw new InvalidArgumentException('Provider-specific high-volume threshold must be positive when supplied.');
        }

        if (($this->highVolumeThreshold === null) !== ($this->thresholdUnit === null)) {
            throw new InvalidArgumentException('Threshold value and unit must be supplied together or both omitted.');
        }

        if ($this->thresholdUnit !== null && trim($this->thresholdUnit) === '') {
            throw new InvalidArgumentException('Threshold unit must not be blank when supplied.');
        }

        if ($this->freshUntil !== null && $this->freshUntil <= $this->observedAt) {
            throw new InvalidArgumentException('Provider policy freshness must extend beyond observation time.');
        }

        $parts = parse_url($this->provenanceUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (filter_var($this->provenanceUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Provider policy provenance must be an HTTP or HTTPS URL.');
        }

        self::assertPublicPolicyPayload($this->classificationInputs, 'classification_inputs');
        self::assertPublicPolicyPayload($this->requirements, 'requirements');
    }

    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        return $this->effectiveFrom <= $at
            && ($this->effectiveUntil === null || $this->effectiveUntil > $at);
    }

    public function isFreshAt(DateTimeImmutable $at): bool
    {
        return $this->observedAt <= $at
            && $this->freshUntil !== null
            && $this->freshUntil > $at;
    }

    private static function assertPublicPolicyPayload(array $payload, string $path): void
    {
        foreach ($payload as $key => $value) {
            $current = $path.'.'.(string) $key;
            $normalized = strtolower((string) $key);

            foreach (['password', 'secret', 'private_key', 'signing_key', 'api_key', 'access_token', 'refresh_token', 'credential', 'authorization'] as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    throw new InvalidArgumentException('Provider policy data must not contain secret material at '.$current.'.');
                }
            }

            if (is_array($value)) {
                self::assertPublicPolicyPayload($value, $current);

                continue;
            }

            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Provider policy data must be JSON-safe at '.$current.'.');
            }
        }
    }
}
