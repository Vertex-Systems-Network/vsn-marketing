<?php

namespace App\Modules\Consent\Domain\Unsubscribe;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OpaqueUnsubscribeToken
{
    public string $digest;

    public function __construct(
        public string $value,
        public UnsubscribeScope $scope,
        public DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{32,256}$/', $value) !== 1) {
            throw new InvalidArgumentException('One-click unsubscribe token must be opaque base64url-safe material.');
        }

        if ($expiresAt !== null && $expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Unsubscribe token expiry must be after issuance.');
        }

        $this->digest = hash('sha256', $value);
    }

    public static function issue(
        UnsubscribeScope $scope,
        DateTimeImmutable $issuedAt,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        $opaque = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new self($opaque, $scope, $issuedAt, $expiresAt);
    }

    public function isExpiredAt(DateTimeImmutable $at): bool
    {
        return $this->expiresAt !== null && $at >= $this->expiresAt;
    }
}
