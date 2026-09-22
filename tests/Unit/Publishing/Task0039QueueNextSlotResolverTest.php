<?php

use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleRuleSet;
use App\Modules\Publishing\Domain\Scheduling\LocalScheduleTimeResolver;
use App\Modules\Publishing\Domain\Scheduling\QueueNextSlotResolver;
use DateTimeImmutable;
use InvalidArgumentException;

function task0039QueueRule(
    string $id,
    string $timezoneId,
    array $slots,
    int $version = 1,
    ?string $parentId = null,
): CampaignScheduleRuleSet {
    return CampaignScheduleRuleSet::create(
        id: $id,
        workspaceId: 'workspace-task0039',
        parentRuleSetId: $parentId,
        channel: 'email',
        versionNumber: $version,
        timezoneId: $timezoneId,
        slots: $slots,
        idempotencyKey: 'rule-'.$id,
        createdByActorId: 'actor-task0039',
        createdAt: new DateTimeImmutable('2026-07-15T10:00:00+00:00'),
    );
}

it('selects the deterministic next weekly slot and canonicalizes slot ordering', function () {
    $rule = task0039QueueRule(
        'rule-v1',
        'America/New_York',
        [
            ['local_time' => '10:30:00', 'weekday' => 3],
            ['weekday' => 3, 'local_time' => '09:30:00'],
            ['weekday' => 5, 'local_time' => '08:00:00'],
        ],
    );

    $resolved = (new QueueNextSlotResolver(new LocalScheduleTimeResolver))->resolve(
        $rule,
        new DateTimeImmutable('2026-07-15T12:45:00+00:00'),
    );

    expect($rule->slots)->toBe([
        ['weekday' => 3, 'local_time' => '09:30:00'],
        ['weekday' => 3, 'local_time' => '10:30:00'],
        ['weekday' => 5, 'local_time' => '08:00:00'],
    ])->and($resolved->localScheduledAt)->toBe('2026-07-15T09:30:00')
        ->and($resolved->resolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00');
});

it('advances to the next weekly cycle when all current-day slots are already past', function () {
    $rule = task0039QueueRule(
        'rule-cycle',
        'UTC',
        [
            ['weekday' => 3, 'local_time' => '09:00:00'],
        ],
    );

    $resolved = (new QueueNextSlotResolver(new LocalScheduleTimeResolver))->resolve(
        $rule,
        new DateTimeImmutable('2026-07-15T10:00:00+00:00'),
    );

    expect($resolved->localScheduledAt)->toBe('2026-07-22T09:00:00')
        ->and($resolved->resolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-22T09:00:00+00:00');
});

it('fails closed when the next pinned queue occurrence falls in a DST gap', function () {
    $rule = task0039QueueRule(
        'rule-gap',
        'America/New_York',
        [
            ['weekday' => 7, 'local_time' => '02:30:00'],
        ],
    );

    expect(fn () => (new QueueNextSlotResolver(new LocalScheduleTimeResolver))->resolve(
        $rule,
        new DateTimeImmutable('2026-03-07T12:00:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'Campaign queue next-slot occurrence is invalid under the pinned timezone/DST rule.',
    );
});

it('fails closed when the next pinned queue occurrence is DST-ambiguous', function () {
    $rule = task0039QueueRule(
        'rule-overlap',
        'America/New_York',
        [
            ['weekday' => 7, 'local_time' => '01:30:00'],
        ],
    );

    expect(fn () => (new QueueNextSlotResolver(new LocalScheduleTimeResolver))->resolve(
        $rule,
        new DateTimeImmutable('2026-10-31T12:00:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'Campaign queue next-slot occurrence is invalid under the pinned timezone/DST rule.',
    );
});

it('rejects duplicate weekly slots and legacy timezone aliases', function () {
    expect(fn () => task0039QueueRule(
        'rule-duplicate',
        'UTC',
        [
            ['weekday' => 3, 'local_time' => '09:00:00'],
            ['weekday' => 3, 'local_time' => '09:00:00'],
        ],
    ))->toThrow(InvalidArgumentException::class, 'duplicate weekly slot');

    expect(fn () => task0039QueueRule(
        'rule-alias',
        'US/Eastern',
        [
            ['weekday' => 3, 'local_time' => '09:00:00'],
        ],
    ))->toThrow(InvalidArgumentException::class, 'canonical IANA timezone');
});
