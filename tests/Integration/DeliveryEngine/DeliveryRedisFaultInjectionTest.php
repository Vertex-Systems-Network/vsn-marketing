<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator;
use Illuminate\Redis\RedisManager;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run Redis-backed fault tests.');
    }

    app(RedisManager::class)->connection('locks')->flushdb();
});

function task0023RedisCoordinatorAt(string $instant): array
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

it('reconnects after client interruption without consuming duplicate capacity', function () {
    [, $coordinator] = task0023RedisCoordinatorAt('2026-09-12T00:00:00+00:00');
    $redis = app(RedisManager::class);

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 30))->toBeTrue();

    $redis->disconnect('locks');

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 30))->toBeTrue()
        ->and($coordinator->tryAcquire('workspace-a', 'operation-2', 1, 1, 30))->toBeFalse();
});

it('releases capacity after reconnect so a different operation can proceed', function () {
    [, $coordinator] = task0023RedisCoordinatorAt('2026-09-12T00:00:00+00:00');
    $redis = app(RedisManager::class);

    expect($coordinator->tryAcquire('workspace-a', 'operation-1', 1, 1, 30))->toBeTrue();

    $redis->disconnect('locks');
    $coordinator->release('workspace-a', 'operation-1');

    expect($coordinator->tryAcquire('workspace-a', 'operation-2', 1, 1, 30))->toBeTrue();
});

it('recovers deterministically from an expired capacity lease after worker loss', function () {
    [$clock, $coordinator] = task0023RedisCoordinatorAt('2026-09-12T00:00:00+00:00');

    expect($coordinator->tryAcquire('workspace-a', 'operation-abandoned', 1, 1, 5))->toBeTrue();

    app(RedisManager::class)->disconnect('locks');
    $clock->instant = new DateTimeImmutable('2026-09-12T00:00:06+00:00');

    expect($coordinator->tryAcquire('workspace-b', 'operation-recovered', 1, 1, 5))->toBeTrue();
});

it('keeps bounded capacity under repeated reconnect and recovery cycles', function () {
    [$clock, $coordinator] = task0023RedisCoordinatorAt('2026-09-12T00:00:00+00:00');
    $redis = app(RedisManager::class);
    $evidence = [];

    for ($cycle = 0; $cycle < 8; $cycle++) {
        $operationId = 'operation-'.$cycle;
        $startedAt = hrtime(true);

        expect($coordinator->tryAcquire('workspace-a', $operationId, 1, 2, 2))->toBeTrue()
            ->and($coordinator->tryAcquire('workspace-a', 'blocked-'.$cycle, 1, 2, 2))->toBeFalse();

        $redis->disconnect('locks');
        $clock->instant = $clock->instant->modify('+3 seconds');
        $evidence[] = hrtime(true) - $startedAt;
    }

    expect($evidence)->toHaveCount(8)
        ->and(min($evidence))->toBeGreaterThanOrEqual(0)
        ->and(max($evidence))->toBeGreaterThanOrEqual(min($evidence))
        ->and($coordinator->tryAcquire('workspace-b', 'final-operation', 1, 2, 2))->toBeTrue();
});
