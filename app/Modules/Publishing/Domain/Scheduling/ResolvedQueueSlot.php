<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use DateTimeImmutable;

final readonly class ResolvedQueueSlot
{
    public function __construct(
        public string $localScheduledAt,
        public DateTimeImmutable $resolvedAtUtc,
    ) {}
}
