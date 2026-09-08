<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator;
use DateTimeImmutable;
use Illuminate\Redis\RedisManager;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run Redis-backed admission tests.');
    }

    app(RedisManager::class)->connection('locks')->flushdb();
});

function redisAdmissionCoordinatorAt(string $instant): array
{
    $clock = new class(new DateTimeImmutable($instant)) implements Clock
    {
        public function __construct(public DateTimeImmutable $instant) {}

        public function now(): DateTimeImmutable
        {
            return $this->instant;
        }
    };

    return [$clock, new RedisDeliveryAdmissionCoordinator(app(RedisManager::class), $clock)];
}

it('atomically enforces both workspace and global concurrency limits', function () {
    [, $coordinator] = redisAdmissionCoordinatorAt('2026-09-08T15:00:00+00:00');

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-a', 'operation-2', 1, 2, 30))->toBeFalse()
        ->and($coordinator->tryAcquire('workspace-b', 'operation-3', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-c', 'operation-4', 1, 2, 30))->toBeFalse();
});

it('treats repeated acquisition of the same logical operation as idempotent', function () {
    [, $coordinator] = redisAdmissionCoordinatorAt('2026-09-08T15:00:00+00:00');

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-a', 'operation-2', 1, 1, 30))->toBeFalse();
});

it('releases reservations so capacity can be reused deterministically', function () {
    [, $coordinator] = redisAdmissionCoordinatorAt('2026-09-08T15:00:00+00:00');

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 30))->toBeTrue();

    $coordinator->release('workspace-a', 'operation-1');

    expect($coordinator->tryAcquire('workspace-a', 'operation-2', 1, 1, 30))->toBeTrue();
});

it('cleans expired reservations before evaluating new capacity', function () {
    [$clock, $coordinator] = redisAdmissionCoordinatorAt('2026-09-08T15:00:00+00:00');

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 5))->toBeTrue();

    $clock->instant = new DateTimeImmutable('2026-09-08T15:00:06+00:00');

    expect($coordinator->tryAcquire('workspace-b', 'operation-2', 1, 1, 5))->toBeTrue();
});

it('fails closed on invalid concurrency evidence', function () {
    [, $coordinator] = redisAdmissionCoordinatorAt('2026-09-08T15:00:00+00:00');

    expect(fn () => $coordinator->tryAcquire('workspace-a', 'operation-1', 2, 1, 30))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $coordinator->tryAcquire('', 'operation-1', 1, 1, 30))
        ->toThrow(InvalidArgumentException::class);
});
