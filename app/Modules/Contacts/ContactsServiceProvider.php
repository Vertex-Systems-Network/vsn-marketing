<?php

namespace App\Modules\Contacts;

use Illuminate\Support\ServiceProvider;
use App\Modules\Contacts\Application\Actions\CreateContact;

final class ContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreateContact::class);
    }

    public function boot(): void
    {
        // Migrations are loaded via default Laravel convention from database/migrations
    }
}
