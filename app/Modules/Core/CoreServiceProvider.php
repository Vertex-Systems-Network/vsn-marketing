<?php

namespace App\Modules\Core;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Infrastructure\Time\SystemClock;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
    }
}
