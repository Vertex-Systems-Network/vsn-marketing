<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CampaignScheduleRuleSet
{
    /**
     * @param  list<array{weekday: int, local_time: string}>  $slots
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $parentRuleSetId,
        public string $channel,
        public int $versionNumber,
        public string $timezoneId,
        public array $slots,
        public string $ruleHash,
        public string $idempotencyKey,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'scheduleRule.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'scheduleRule.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->channel, 'scheduleRule.channel');
        CampaignPayloadGuard::assertIdentifier($this->timezoneId, 'scheduleRule.timezoneId');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'scheduleRule.idempotencyKey');
        CampaignPayloadGuard::assertIdentifier($this->createdByActorId, 'scheduleRule.createdByActorId');
        CampaignPayloadGuard::assertSha256($this->ruleHash, 'scheduleRule.ruleHash');

        if ($this->parentRuleSetId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->parentRuleSetId, 'scheduleRule.parentRuleSetId');
        }

        if ($this->versionNumber < 1) {
            throw new InvalidArgumentException('Campaign schedule rule version must be at least 1.');
        }

        if (($this->versionNumber === 1) !== ($this->parentRuleSetId === null)) {
            throw new InvalidArgumentException('Campaign schedule rule parent must be null only for version 1.');
        }

        if (! self::isCanonicalTimezone($this->timezoneId)) {
            throw new InvalidArgumentException('Campaign schedule rule timezone must be a canonical IANA timezone identifier.');
        }

        if ($this->slots === []) {
            throw new InvalidArgumentException('Campaign schedule rule must contain at least one weekly slot.');
        }

        $canonicalSlots = self::canonicalizeSlots($this->slots);
        if ($canonicalSlots !== $this->slots) {
            throw new InvalidArgumentException('Campaign schedule rule slots must be canonical and deterministically ordered.');
        }

        $expectedHash = CampaignPayloadGuard::hash($this->canonicalPayload());
        if (! hash_equals($expectedHash, $this->ruleHash)) {
            throw new InvalidArgumentException('Campaign schedule rule hash does not match canonical rule payload.');
        }
    }

    /**
     * @param  list<array{weekday: int, local_time: string}>  $slots
     */
    public static function create(
        string $id,
        string $workspaceId,
        ?string $parentRuleSetId,
        string $channel,
        int $versionNumber,
        string $timezoneId,
        array $slots,
        string $idempotencyKey,
        string $createdByActorId,
        DateTimeImmutable $createdAt,
    ): self
    {
        $canonicalSlots = self::canonicalizeSlots($slots);
        $payload = self::canonicalPayloadFor(
            workspaceId: $workspaceId,
            parentRuleSetId: $parentRuleSetId,
            channel: $channel,
            versionNumber: $versionNumber,
            timezoneId: $timezoneId,
            slots: $canonicalSlots,
        );

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            parentRuleSetId: $parentRuleSetId,
            channel: $channel,
            versionNumber: $versionNumber,
            timezoneId: $timezoneId,
            slots: $canonicalSlots,
            ruleHash: CampaignPayloadGuard::hash($payload),
            idempotencyKey: $idempotencyKey,
            createdByActorId: $createdByActorId,
            createdAt: $createdAt,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return self::canonicalPayloadFor(
            workspaceId: $this->workspaceId,
            parentRuleSetId: $this->parentRuleSetId,
            channel: $this->channel,
            versionNumber: $this->versionNumber,
            timezoneId: $this->timezoneId,
            slots: $this->slots,
        );
    }

    /**
     * @param  list<array{weekday: int, local_time: string}>  $slots
     * @return list<array{weekday: int, local_time: string}>
     */
    private static function canonicalizeSlots(array $slots): array
    {
        if (! array_is_list($slots)) {
            throw new InvalidArgumentException('Campaign schedule rule slots must be a list.');
        }

        $seen = [];
        $canonical = [];

        foreach ($slots as $slot) {
            if (
                ! is_array($slot)
                || count($slot) !== 2
                || ! array_key_exists('weekday', $slot)
                || ! array_key_exists('local_time', $slot)
            ) {
                throw new InvalidArgumentException('Campaign schedule rule slot must contain weekday and local_time only.');
            }

            $weekday = $slot['weekday'];
            $localTime = $slot['local_time'];

            if (! is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('Campaign schedule rule weekday must be an ISO weekday from 1 through 7.');
            }

            if (
                ! is_string($localTime)
                || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $localTime) !== 1
            ) {
                throw new InvalidArgumentException('Campaign schedule rule local_time must use HH:MM:SS.');
            }

            $key = $weekday.'|'.$localTime;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Campaign schedule rule contains a duplicate weekly slot.');
            }

            $seen[$key] = true;
            $canonical[] = ['weekday' => $weekday, 'local_time' => $localTime];
        }

        usort(
            $canonical,
            static fn (array $left, array $right): int => [$left['weekday'], $left['local_time']]
                <=> [$right['weekday'], $right['local_time']],
        );

        return $canonical;
    }

    /**
     * @param  list<array{weekday: int, local_time: string}>  $slots
     * @return array<string, mixed>
     */
    private static function canonicalPayloadFor(
        string $workspaceId,
        ?string $parentRuleSetId,
        string $channel,
        int $versionNumber,
        string $timezoneId,
        array $slots,
    ): array
    {
        return [
            'workspace_id' => $workspaceId,
            'parent_rule_set_id' => $parentRuleSetId,
            'channel' => $channel,
            'version_number' => $versionNumber,
            'timezone_id' => $timezoneId,
            'slots' => $slots,
        ];
    }

    private static function isCanonicalTimezone(string $timezoneId): bool
    {
        return $timezoneId === 'UTC'
            || in_array($timezoneId, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true);
    }
}
