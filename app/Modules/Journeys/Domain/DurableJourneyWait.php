<?php

namespace App\Modules\Journeys\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** A persisted wake-up deadline descriptor. Workers schedule this value; they never sleep. */
final readonly class DurableJourneyWait
{
    private function __construct(
        public string $workspaceId,
        public string $executionId,
        public string $nodeId,
        public DateTimeImmutable $wakeAt,
        public string $idempotencyKey,
    ) {}

    public static function schedule(string $workspaceId, string $executionId, string $nodeId, DateTimeImmutable $now, int $seconds, JourneyRuntimePolicy $policy): self
    {
        foreach ([$workspaceId, $executionId, $nodeId] as $value) {
            if (trim($value) === '') {
                throw new JourneyDefinitionException('invalid_wait_identity', '$.wait');
            }
        }
        $policy->assertWait($seconds);
        $utc = new DateTimeZone('UTC');
        $wakeAt = $now->setTimezone($utc)->modify('+'.$seconds.' seconds');
        $key = hash('sha256', json_encode([$workspaceId, $executionId, $nodeId, $wakeAt->format('Y-m-d\TH:i:s.u\Z')], JSON_THROW_ON_ERROR));

        return new self($workspaceId, $executionId, $nodeId, $wakeAt, $key);
    }
}
