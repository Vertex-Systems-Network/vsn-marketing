<?php

namespace App\Modules\Journeys\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class JourneyTrigger
{
    private function __construct(
        public string $workspaceId,
        public string $kind,
        public string $key,
        public string $timezone,
        public DateTimeImmutable $effectiveAt,
        public string $idempotencyKey,
    ) {}

    public static function event(string $workspaceId, string $eventId, DateTimeImmutable $occurredAt): self
    {
        self::assertIdentity($workspaceId, $eventId);
        $utc = new DateTimeZone('UTC');
        $at = $occurredAt->setTimezone($utc);

        return new self($workspaceId, 'event', $eventId, 'UTC', $at, hash('sha256', json_encode([$workspaceId, 'event', $eventId], JSON_THROW_ON_ERROR)));
    }

    public static function schedule(string $workspaceId, string $scheduleId, string $timezone, DateTimeImmutable $scheduledAt): self
    {
        self::assertIdentity($workspaceId, $scheduleId);
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable $exception) {
            throw new JourneyDefinitionException('invalid_trigger_timezone', '$.trigger.timezone');
        }
        $at = $scheduledAt->setTimezone($zone);

        return new self($workspaceId, 'schedule', $scheduleId, $zone->getName(), $at, hash('sha256', json_encode([$workspaceId, 'schedule', $scheduleId, $at->format('Y-m-d\TH:i:s.uP')], JSON_THROW_ON_ERROR)));
    }

    private static function assertIdentity(string $workspaceId, string $key): void
    {
        if (trim($workspaceId) === '' || trim($key) === '') {
            throw new JourneyDefinitionException('invalid_trigger_identity', '$.trigger');
        }
    }
}
