<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator;
use Illuminate\Redis\RedisManager;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0024 Redis certification.');
    }

    app(RedisManager::class)->connection('locks')->flushdb();
});

/** @return array{0: object, 1: RedisDeliveryAdmissionCoordinator} */
function phase04RedisCoordinatorAt(string $instant): array
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

it('certifies reconnect and release preserve idempotent bounded Redis admission', function () {
    [, $coordinator] = phase04RedisCoordinatorAt('2026-09-12T00:00:00+00:00');
    $redis = app(RedisManager::class);

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-a', 'operation-2', 1, 2, 30))->toBeFalse()
        ->and($coordinator->tryAcquire('workspace-b', 'operation-3', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-c', 'operation-4', 1, 2, 30))->toBeFalse();

    $redis->disconnect('locks');

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-c', 'operation-4', 1, 2, 30))->toBeFalse();

    $coordinator->release('workspace-b', 'operation-3');

    expect($coordinator->tryAcquire('workspace-c', 'operation-4', 1, 2, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-c', 'operation-5', 1, 2, 30))->toBeFalse();
});

it('certifies expired worker leases recover capacity without duplicate reservations', function () {
    [$clock, $coordinator] = phase04RedisCoordinatorAt('2026-09-12T00:00:00+00:00');
    $redis = app(RedisManager::class);

    expect($coordinator->tryAcquire('workspace-a', 'operation-abandoned', 1, 1, 5))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-b', 'operation-blocked', 1, 1, 5))->toBeFalse();

    $redis->disconnect('locks');
    $clock->instant = new DateTimeImmutable('2026-09-12T00:00:06+00:00');

    expect($coordinator->tryAcquire('workspace-b', 'operation-recovered', 1, 1, 5))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-b', 'operation-recovered', 1, 1, 5))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-c', 'operation-overflow', 1, 1, 5))->toBeFalse();
});
