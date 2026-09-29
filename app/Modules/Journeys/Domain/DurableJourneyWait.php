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

    public static function restore(string $workspaceId, string $executionId, string $nodeId, DateTimeImmutable $wakeAt, string $idempotencyKey): self
    {
        foreach ([$workspaceId, $executionId, $nodeId] as $value) {
            if (trim($value) === '') {
                throw new JourneyDefinitionException('invalid_wait_identity', '$.wait');
            }
        }
        $utc = new DateTimeZone('UTC');
        $wakeAt = $wakeAt->setTimezone($utc);
        $expectedKey = hash('sha256', json_encode([$workspaceId, $executionId, $nodeId, $wakeAt->format('Y-m-d\TH:i:s.u\Z')], JSON_THROW_ON_ERROR));
        if (! hash_equals($expectedKey, $idempotencyKey)) {
            throw new JourneyDefinitionException('invalid_wait_idempotency_key', '$.wait.idempotency_key');
        }

        return new self($workspaceId, $executionId, $nodeId, $wakeAt, $idempotencyKey);
    }

    public static function schedule(string $workspaceId, string $executionId, string $nodeId, DateTimeImmutable $now, int $seconds, JourneyRuntimePolicy $policy): self
    {
        foreach ([$workspaceId, $executionId, $nodeId] as $value) {
            if (trim($value) === '') {
                throw new JourneyDefinitionException('invalid_wait_identity', '$.wait');
            }
        }
        $policy->assertWait($seconds);
        $utc = new DateTimeZone('UTC');
        // The persisted wake_at column has second precision. Round forward before
        // hashing so the stored deadline can always restore its identity.
        $instant = $now->setTimezone($utc);
        $wakeAt = $instant->setTime((int) $instant->format('H'), (int) $instant->format('i'),
            (int) $instant->format('s'))->modify('+'.($seconds + ((int) $instant->format('u') > 0 ? 1 : 0)).' seconds');
        $key = hash('sha256', json_encode([$workspaceId, $executionId, $nodeId, $wakeAt->format('Y-m-d\TH:i:s.u\Z')], JSON_THROW_ON_ERROR));

        return new self($workspaceId, $executionId, $nodeId, $wakeAt, $key);
    }
}
