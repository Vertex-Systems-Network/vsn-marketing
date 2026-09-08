<?php

namespace App\Modules\DeliveryEngine;

use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryOperationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\Contracts\RecipientSource;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryOperationRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryTransaction;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseRecipientSource;
use Illuminate\Support\ServiceProvider;

final class DeliveryEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeliveryRepository::class, DatabaseDeliveryRepository::class);
        $this->app->singleton(DeliveryOperationRepository::class, DatabaseDeliveryOperationRepository::class);
        $this->app->singleton(DeliveryAdmissionRepository::class, DatabaseDeliveryAdmissionRepository::class);
        $this->app->singleton(RecipientSource::class, DatabaseRecipientSource::class);
        $this->app->singleton(DeliveryTransaction::class, DatabaseDeliveryTransaction::class);
    }
}
