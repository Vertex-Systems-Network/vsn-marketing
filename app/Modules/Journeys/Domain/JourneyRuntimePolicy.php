<?php

namespace App\Modules\Journeys\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class JourneyRuntimePolicy
{
    public function __construct(
        public int $maxWaitSeconds = 31536000,
        public int $maxRetries = 3,
        public int $maxFanOut = 1000,
    ) {
        if ($maxWaitSeconds < 0 || $maxRetries < 0 || $maxFanOut < 1 || $maxFanOut > JourneyGraphValidator::MAX_NODES) {
            throw new JourneyDefinitionException('invalid_runtime_budget', '$.runtime_policy');
        }
    }

    public function assertWait(int $seconds): void
    {
        if ($seconds < 0 || $seconds > $this->maxWaitSeconds) {
            throw new JourneyDefinitionException('wait_bound_exceeded', '$.config.seconds');
        }
    }

    public function deadline(DateTimeImmutable $now, int $seconds, ?string $timezone = null): DateTimeImmutable
    {
        $this->assertWait($seconds);
        $zone = new DateTimeZone($timezone ?: 'UTC');

        return $now->setTimezone($zone)->modify('+'.$seconds.' seconds')->setTimezone(new DateTimeZone('UTC'));
    }
}
