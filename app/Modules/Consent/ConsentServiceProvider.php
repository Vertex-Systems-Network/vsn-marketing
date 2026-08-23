<?php

namespace App\Modules\Consent;

use App\Modules\Consent\Domain\Contracts\ConsentRecordRepository;
use App\Modules\Consent\Domain\Contracts\ConsentTransaction;
use App\Modules\Consent\Infrastructure\DatabaseConsentRecordRepository;
use App\Modules\Consent\Infrastructure\DatabaseConsentTransaction;
use Illuminate\Support\ServiceProvider;

final class ConsentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConsentRecordRepository::class, DatabaseConsentRecordRepository::class);
        $this->app->singleton(ConsentTransaction::class, DatabaseConsentTransaction::class);
    }
}
