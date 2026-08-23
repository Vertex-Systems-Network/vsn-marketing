<?php

namespace App\Modules\Contacts;

use App\Modules\Contacts\Domain\Contracts\CompanyRepository;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Contacts\Infrastructure\DatabaseCompanyRepository;
use App\Modules\Contacts\Infrastructure\DatabaseContactRepository;
use App\Modules\Contacts\Infrastructure\DatabaseContactTransaction;
use Illuminate\Support\ServiceProvider;

final class ContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContactRepository::class, DatabaseContactRepository::class);
        $this->app->singleton(CompanyRepository::class, DatabaseCompanyRepository::class);
        $this->app->singleton(ContactTransaction::class, DatabaseContactTransaction::class);
    }
}
