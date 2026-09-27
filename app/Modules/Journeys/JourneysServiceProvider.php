<?php

namespace App\Modules\Journeys;

use App\Modules\Journeys\Application\JourneyRegistry;
use App\Modules\Journeys\Domain\Contracts\JourneyWaitRepository;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyNodeRegistry;
use App\Modules\Journeys\Infrastructure\Persistence\DatabaseJourneyWaitRepository;
use Illuminate\Support\ServiceProvider;

final class JourneysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JourneyGraphValidator::class);
        $this->app->singleton(JourneyNodeRegistry::class);
        $this->app->singleton(JourneyRegistry::class);
        $this->app->bind(JourneyWaitRepository::class, DatabaseJourneyWaitRepository::class);
    }
}
