<?php

namespace App\Modules\Consent\Domain\Suppression;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class SuppressionRecord
{
    public string $evidenceFingerprint;

    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $contactId,
        public string $channel,
        public string $purpose,
        public SuppressionAuthorityType $authorityType,
        public string $sourceType,
        public ?string $sourceVersion,
        public ?string $providerKey,
        public string $idempotencyKey,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $effectiveAt,
        public ?DateTimeImmutable $freshUntil,
        public array $immutableEvidence,
        public array $metadata = [],
    ) {
        foreach ([
            'id' => $id,
            'workspaceId' => $workspaceId,
            'contactId' => $contactId,
            'channel' => $channel,
            'purpose' => $purpose,
            'sourceType' => $sourceType,
            'idempotencyKey' => $idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must not be blank.');
            }
        }

        self::assertLength($channel, 64, 'channel');
        self::assertLength($purpose, 120, 'purpose');
        self::assertLength($sourceType, 64, 'sourceType');
        self::assertNullableLength($sourceVersion, 120, 'sourceVersion');
        self::assertNullableLength($providerKey, 120, 'providerKey');
        self::assertLength($idempotencyKey, 191, 'idempotencyKey');

        if ($effectiveAt < $observedAt) {
            throw new InvalidArgumentException('effectiveAt must not precede observedAt.');
        }

        if ($freshUntil !== null && $freshUntil < $observedAt) {
            throw new InvalidArgumentException('freshUntil must not precede observedAt.');
        }

        SuppressionRecordGuard::assertSafe($immutableEvidence, 'immutableEvidence');
        SuppressionRecordGuard::assertSafe($metadata, 'metadata');
        $this->evidenceFingerprint = self::fingerprint($immutableEvidence);
    }

    /** @throws JsonException */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function assertLength(string $value, int $max, string $field): void
    {
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException($field.' exceeds storage limit.');
        }
    }

    private static function assertNullableLength(?string $value, int $max, string $field): void
    {
        if ($value !== null) {
            self::assertLength($value, $max, $field);
        }
    }

    private static function canonicalize(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_map(
                fn (mixed $value): mixed => is_array($value) ? self::canonicalize($value) : $value,
                $payload,
            );
        }

        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::canonicalize($value);
            }
        }

        return $payload;
    }
}
