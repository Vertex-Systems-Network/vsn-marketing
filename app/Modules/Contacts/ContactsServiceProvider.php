<?php

namespace App\Modules\Contacts;

use App\Modules\Contacts\Domain\Contracts\CompanyRepository;
use App\Modules\Contacts\Domain\Contracts\ContactListRepository;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Contacts\Domain\Contracts\TagRepository;
use App\Modules\Contacts\Infrastructure\DatabaseCompanyRepository;
use App\Modules\Contacts\Infrastructure\DatabaseContactListRepository;
use App\Modules\Contacts\Infrastructure\DatabaseContactRepository;
use App\Modules\Contacts\Infrastructure\DatabaseContactTransaction;
use App\Modules\Contacts\Infrastructure\DatabaseTagRepository;
use Illuminate\Support\ServiceProvider;

final class ContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContactRepository::class, DatabaseContactRepository::class);
        $this->app->singleton(CompanyRepository::class, DatabaseCompanyRepository::class);
        $this->app->singleton(ContactListRepository::class, DatabaseContactListRepository::class);
        $this->app->singleton(TagRepository::class, DatabaseTagRepository::class);
        $this->app->singleton(ContactTransaction::class, DatabaseContactTransaction::class);
    }
}
