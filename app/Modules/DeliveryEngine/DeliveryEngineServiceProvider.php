<?php

namespace App\Modules\DeliveryEngine;

use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryFailoverEligibilityRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryOperationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryReconciliationRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRecoveryRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\Contracts\RecipientSource;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryFailoverEligibilityRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryOperationRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryReconciliationRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryRecoveryRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryRepository;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseDeliveryTransaction;
use App\Modules\DeliveryEngine\Infrastructure\DatabaseRecipientSource;
use App\Modules\DeliveryEngine\Infrastructure\RedisDeliveryAdmissionCoordinator;
use Illuminate\Support\ServiceProvider;

final class DeliveryEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeliveryRepository::class, DatabaseDeliveryRepository::class);
        $this->app->singleton(DeliveryOperationRepository::class, DatabaseDeliveryOperationRepository::class);
        $this->app->singleton(DeliveryAdmissionRepository::class, DatabaseDeliveryAdmissionRepository::class);
        $this->app->singleton(DeliveryRecoveryRepository::class, DatabaseDeliveryRecoveryRepository::class);
        $this->app->singleton(DeliveryReconciliationRepository::class, DatabaseDeliveryReconciliationRepository::class);
        $this->app->singleton(
            DeliveryFailoverEligibilityRepository::class,
            DatabaseDeliveryFailoverEligibilityRepository::class,
        );
        $this->app->singleton(DeliveryAdmissionCoordinator::class, RedisDeliveryAdmissionCoordinator::class);
        $this->app->singleton(RecipientSource::class, DatabaseRecipientSource::class);
        $this->app->singleton(DeliveryTransaction::class, DatabaseDeliveryTransaction::class);
    }
}
