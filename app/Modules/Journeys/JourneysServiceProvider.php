<?php

namespace App\Modules\Journeys;

use App\Modules\Journeys\Application\JourneyRegistry;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use Illuminate\Support\ServiceProvider;

final class JourneysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JourneyGraphValidator::class);
        $this->app->singleton(JourneyRegistry::class);
    }
}
