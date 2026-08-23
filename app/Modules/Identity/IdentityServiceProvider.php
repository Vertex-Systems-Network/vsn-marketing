<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContextStore::class, fn (): TenantContextStore => new TenantContextStore);
    }
}
