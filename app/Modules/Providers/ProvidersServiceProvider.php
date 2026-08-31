<?php

namespace App\Modules\Providers;

use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\Contracts\ProviderTransaction;
use App\Modules\Providers\Infrastructure\DatabaseProviderRepository;
use App\Modules\Providers\Infrastructure\DatabaseProviderTransaction;
use Illuminate\Support\ServiceProvider;

final class ProvidersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRepository::class, DatabaseProviderRepository::class);
        $this->app->singleton(ProviderTransaction::class, DatabaseProviderTransaction::class);
    }
}
