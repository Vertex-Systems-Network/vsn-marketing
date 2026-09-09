<?php

use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator;

it('binds the delivery admission coordinator to the Redis implementation', function () {
    expect(app(DeliveryAdmissionCoordinator::class))
        ->toBeInstanceOf(RedisDeliveryAdmissionCoordinator::class);
});

it('disables production concurrency coordination only in the standard isolated test runtime', function () {
    expect(config('delivery.admission.concurrency_enabled'))->toBeFalse();
});
