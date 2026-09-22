<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class LocalScheduleTimeResolver
{
    private const FORMAT = 'Y-m-d\\TH:i:s';

    public function resolve(string $timezoneId, string $localAt): DateTimeImmutable
    {
        if (! $this->isIanaTimezone($timezoneId)) {
            throw new InvalidArgumentException('Campaign schedule timezone must be a canonical IANA timezone identifier.');
        }

        $wall = DateTimeImmutable::createFromFormat(
            '!'.self::FORMAT,
            $localAt,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            ! $wall instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $wall->format(self::FORMAT) !== $localAt
        ) {
            throw new InvalidArgumentException(
                'Campaign fixed-instant local time must use strict YYYY-MM-DDTHH:MM:SS wall-clock format.',
            );
        }

        $timezone = new DateTimeZone($timezoneId);
        $wallTimestamp = $wall->getTimestamp();
        $transitions = $timezone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);

        if ($transitions === false || $transitions === []) {
            throw new InvalidArgumentException('Campaign schedule timezone transitions could not be resolved.');
        }

        $offsets = [];
        foreach ($transitions as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }

        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $candidateTimestamp = $wallTimestamp - $offset;
            $candidate = (new DateTimeImmutable('@'.$candidateTimestamp))->setTimezone($timezone);

            if ($candidate->format(self::FORMAT) !== $localAt) {
                continue;
            }

            $candidates[(string) $candidateTimestamp] = $candidate->setTimezone(new DateTimeZone('UTC'));
        }

        if ($candidates === []) {
            throw new InvalidArgumentException(
                'Campaign fixed-instant local time does not exist in the selected timezone because of a DST or civil-time gap.',
            );
        }

        if (count($candidates) !== 1) {
            throw new InvalidArgumentException(
                'Campaign fixed-instant local time is ambiguous in the selected timezone because of a DST or civil-time overlap.',
            );
        }

        return array_values($candidates)[0];
    }

    private function isIanaTimezone(string $timezoneId): bool
    {
        if ($timezoneId === 'UTC') {
            return true;
        }

        return in_array(
            $timezoneId,
            DateTimeZone::listIdentifiers(DateTimeZone::ALL),
            true,
        );
    }
}
