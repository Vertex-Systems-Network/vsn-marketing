<?php

namespace App\Modules\Consent\Application\Unsubscribe;

use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use DateTimeImmutable;

final readonly class AcceptedOneClickUnsubscribe
{
    public function __construct(
        public UnsubscribeScope $scope,
        public string $tokenDigest,
        public string $idempotencyKey,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
