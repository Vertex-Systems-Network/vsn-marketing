<?php

namespace App\Modules\Journeys\Domain;

use InvalidArgumentException;

enum JourneyReentryPolicy: string
{
    case Never = 'never';
    case AfterExit = 'after_exit';
    case Bounded = 'bounded';

    public function allows(int $priorEnrollmentCount, ?string $latestStatus = null, ?int $maximumEnrollments = null): bool
    {
        if ($priorEnrollmentCount < 0) {
            throw new InvalidArgumentException('Prior enrollment count cannot be negative.');
        }

        return match ($this) {
            self::Never => $priorEnrollmentCount === 0,
            self::AfterExit => $priorEnrollmentCount === 0 || $latestStatus === 'exited',
            self::Bounded => $maximumEnrollments !== null && $maximumEnrollments > 0
                && $priorEnrollmentCount < $maximumEnrollments,
        };
    }
}
