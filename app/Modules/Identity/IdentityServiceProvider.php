<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Presentation\Console\GrantPlatformAdmin;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContextStore::class, fn (): TenantContextStore => new TenantContextStore);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GrantPlatformAdmin::class]);
        }
    }
}
