<?php

namespace App\Modules\DeliveryEngine\Domain\SenderAuthentication;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticationEvidence
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $senderDomainId,
        public AuthenticationDimension $dimension,
        public AuthenticationEvidenceStatus $status,
        public string $evidenceVersion,
        public string $sourceType,
        public ?string $providerKey,
        public array $publicMaterial,
        public array $redactedEvidence,
        public ?string $sourceUrl,
        public ?string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
        public DateTimeImmutable $recordedAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'senderDomainId' => $this->senderDomainId,
            'evidenceVersion' => $this->evidenceVersion,
            'sourceType' => $this->sourceType,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }

        if ($this->providerKey !== null && trim($this->providerKey) === '') {
            throw new InvalidArgumentException('Provider key must not be blank when supplied.');
        }

        if ($this->observedAt > $this->recordedAt) {
            throw new InvalidArgumentException('Authentication evidence cannot be recorded before it was observed.');
        }

        if ($this->freshUntil !== null && $this->freshUntil <= $this->observedAt) {
            throw new InvalidArgumentException('Authentication evidence freshness must extend beyond observation time.');
        }

        if ($this->sourceUrl !== null) {
            $parts = parse_url($this->sourceUrl);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

            if (filter_var($this->sourceUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Authentication evidence source URL must use HTTP or HTTPS.');
            }
        }

        self::assertRedacted($this->redactedEvidence, 'redacted_evidence');
    }

    public function isFreshAt(DateTimeImmutable $at): bool
    {
        return $this->observedAt <= $at
            && $this->freshUntil !== null
            && $this->freshUntil > $at;
    }

    private static function assertRedacted(array $payload, string $path): void
    {
        foreach ($payload as $key => $value) {
            $current = $path . '.' . (string) $key;
            $normalized = strtolower((string) $key);

            foreach (['password', 'secret', 'private_key', 'signing_key', 'api_key', 'access_token', 'refresh_token', 'credential', 'authorization'] as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    throw new InvalidArgumentException('Authentication audit evidence must not contain secret material at ' . $current . '.');
                }
            }

            if (is_array($value)) {
                self::assertRedacted($value, $current);

                continue;
            }

            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Authentication evidence must be JSON-safe at ' . $current . '.');
            }

            if (is_string($value) && preg_match('/-----BEGIN (?:ENCRYPTED )?(?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $value) === 1) {
                throw new InvalidArgumentException('Authentication audit evidence must not contain private key material at ' . $current . '.');
            }
        }
    }
}
