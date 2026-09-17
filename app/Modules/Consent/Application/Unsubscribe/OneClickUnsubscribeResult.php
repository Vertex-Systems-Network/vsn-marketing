<?php

namespace App\Modules\Consent\Application\Unsubscribe;

use DateTimeImmutable;

final readonly class OneClickUnsubscribeResult
{
    public function __construct(
        public string $suppressionRecordId,
        public string $tokenDigest,
        public string $idempotencyKey,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
