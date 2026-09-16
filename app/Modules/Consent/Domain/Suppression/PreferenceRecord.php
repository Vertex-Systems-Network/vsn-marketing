<?php

namespace App\Modules\Consent\Domain\Suppression;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PreferenceRecord
{
    public string $evidenceFingerprint;

    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $contactId,
        public string $channel,
        public string $purpose,
        public string $scopeType,
        public ?string $scopeKey,
        public PreferenceDecision $decision,
        public ?string $basisType,
        public string $sourceType,
        public ?string $sourceVersion,
        public string $idempotencyKey,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $effectiveAt,
        public array $immutableEvidence,
        public array $metadata = [],
    ) {
        foreach ([
            'id' => $id,
            'workspaceId' => $workspaceId,
            'contactId' => $contactId,
            'channel' => $channel,
            'purpose' => $purpose,
            'scopeType' => $scopeType,
            'sourceType' => $sourceType,
            'idempotencyKey' => $idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must not be blank.');
            }
        }

        self::assertLength($channel, 64, 'channel');
        self::assertLength($purpose, 120, 'purpose');
        self::assertLength($scopeType, 64, 'scopeType');
        self::assertNullableLength($scopeKey, 191, 'scopeKey');
        self::assertNullableLength($basisType, 64, 'basisType');
        self::assertLength($sourceType, 64, 'sourceType');
        self::assertNullableLength($sourceVersion, 120, 'sourceVersion');
        self::assertLength($idempotencyKey, 191, 'idempotencyKey');

        if ($effectiveAt < $observedAt) {
            throw new InvalidArgumentException('effectiveAt must not precede observedAt.');
        }

        SuppressionRecordGuard::assertSafe($immutableEvidence, 'immutableEvidence');
        SuppressionRecordGuard::assertSafe($metadata, 'metadata');
        $this->evidenceFingerprint = SuppressionRecord::fingerprint($immutableEvidence);
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
}
