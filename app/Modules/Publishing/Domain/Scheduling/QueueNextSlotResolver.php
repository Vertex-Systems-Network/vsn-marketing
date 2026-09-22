<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class QueueNextSlotResolver
{
    public function __construct(private LocalScheduleTimeResolver $localTimeResolver) {}

    public function resolve(
        CampaignScheduleRuleSet $ruleSet,
        DateTimeImmutable $after,
    ): ResolvedQueueSlot {
        $afterUtc = $after->setTimezone(new DateTimeZone('UTC'));
        $localAfter = $afterUtc->setTimezone(new DateTimeZone($ruleSet->timezoneId));
        $startDate = $localAfter->setTime(0, 0, 0);

        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $date = $startDate->modify("+{$dayOffset} days");
            $weekday = (int) $date->format('N');

            foreach ($ruleSet->slots as $slot) {
                if ($slot['weekday'] !== $weekday) {
                    continue;
                }

                $localScheduledAt = $date->format('Y-m-d').'T'.$slot['local_time'];

                try {
                    $resolvedAtUtc = $this->localTimeResolver->resolve(
                        $ruleSet->timezoneId,
                        $localScheduledAt,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidArgumentException(
                        'Campaign queue next-slot occurrence is invalid under the pinned timezone/DST rule.',
                        previous: $exception,
                    );
                }

                if ($resolvedAtUtc <= $afterUtc) {
                    continue;
                }

                return new ResolvedQueueSlot(
                    localScheduledAt: $localScheduledAt,
                    resolvedAtUtc: $resolvedAtUtc,
                );
            }
        }

        throw new InvalidArgumentException('Campaign queue rule did not yield a future slot within one weekly cycle.');
    }
}
