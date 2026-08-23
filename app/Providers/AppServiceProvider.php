<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cross-cutting framework bindings only. Domain bindings belong to modules.
    }

    public function boot(): void
    {
        // Intentionally minimal during the foundation phase.
    }
}
