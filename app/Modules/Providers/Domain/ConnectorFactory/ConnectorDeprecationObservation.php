<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConnectorDeprecationObservation
{
    public function __construct(
        public string $workspaceId,
        public string $providerKey,
        public string $contractVersion,
        public string $sourceUri,
        public string $sourceSha256,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $deprecatedAt,
        public ?DateTimeImmutable $sunsetAt,
    ) {
        $source = parse_url($sourceUri);
        if (trim($workspaceId) === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerKey) !== 1
            || preg_match('/^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)$/D', $contractVersion) !== 1
            || ! is_array($source) || strtolower($source['scheme'] ?? '') !== 'https' || empty($source['host'])
            || isset($source['user']) || isset($source['pass'])
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1) {
            throw new InvalidArgumentException('Deprecation evidence requires tenant, provider, semantic version and credential-free HTTPS provenance.');
        }

        foreach ([$observedAt, $deprecatedAt, $sunsetAt] as $date) {
            if ($date !== null && $date->getOffset() !== 0) {
                throw new InvalidArgumentException('Deprecation observations must use UTC timestamps.');
            }
        }

        if ($deprecatedAt !== null && $deprecatedAt > $observedAt) {
            throw new InvalidArgumentException('A deprecation cannot be observed before its declared date.');
        }

        if ($sunsetAt !== null && ($deprecatedAt === null || $sunsetAt < $deprecatedAt)) {
            throw new InvalidArgumentException('Sunset must follow a known deprecation date.');
        }
    }

    public function alertRequired(): bool
    {
        return $this->deprecatedAt !== null;
    }

    public function sunsetDue(): bool
    {
        return $this->sunsetAt !== null && $this->sunsetAt <= $this->observedAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'contract_version' => $this->contractVersion,
            'source_uri' => $this->sourceUri,
            'source_sha256' => $this->sourceSha256,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'deprecated_at' => $this->deprecatedAt?->format(DATE_ATOM),
            'sunset_at' => $this->sunsetAt?->format(DATE_ATOM),
            'alert_required' => $this->alertRequired(),
            'sunset_due' => $this->sunsetDue(),
            'automatic_upgrade' => false,
        ];
    }
}
