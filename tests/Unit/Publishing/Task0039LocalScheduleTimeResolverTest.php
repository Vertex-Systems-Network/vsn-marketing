<?php

use App\Modules\Publishing\Domain\Scheduling\LocalScheduleTimeResolver;
use InvalidArgumentException;

it('resolves a unique IANA local wall time to one immutable UTC instant', function () {
    $resolved = (new LocalScheduleTimeResolver)->resolve(
        'America/New_York',
        '2026-07-15T09:30:00',
    );

    expect($resolved->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00');
});

it('rejects nonexistent DST gap local times', function () {
    (new LocalScheduleTimeResolver)->resolve(
        'America/New_York',
        '2026-03-08T02:30:00',
    );
})->throws(
    InvalidArgumentException::class,
    'Campaign fixed-instant local time does not exist in the selected timezone because of a DST or civil-time gap.',
);

it('rejects ambiguous DST overlap local times', function () {
    (new LocalScheduleTimeResolver)->resolve(
        'America/New_York',
        '2026-11-01T01:30:00',
    );
})->throws(
    InvalidArgumentException::class,
    'Campaign fixed-instant local time is ambiguous in the selected timezone because of a DST or civil-time overlap.',
);

it('rejects offset-only zones and malformed wall-clock input', function () {
    $resolver = new LocalScheduleTimeResolver;

    expect(fn () => $resolver->resolve('+05:00', '2026-07-15T09:30:00'))
        ->toThrow(InvalidArgumentException::class, 'canonical IANA timezone');

    expect(fn () => $resolver->resolve('UTC', '2026-07-15T09:30:00Z'))
        ->toThrow(InvalidArgumentException::class, 'strict YYYY-MM-DDTHH:MM:SS');
});
