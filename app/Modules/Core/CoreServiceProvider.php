<?php

namespace App\Modules\Core;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\ObjectStore;
use App\Modules\Core\Domain\Contracts\TransactionalOutbox;
use App\Modules\Core\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use App\Modules\Core\Infrastructure\Outbox\RelayOutboxCommand;
use App\Modules\Core\Infrastructure\Storage\LaravelObjectStore;
use App\Modules\Core\Infrastructure\Time\SystemClock;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(TransactionalOutbox::class, DatabaseTransactionalOutbox::class);
        $this->app->singleton(ObjectStore::class, function ($app): LaravelObjectStore {
            return new LaravelObjectStore($app->make(FilesystemManager::class), (string) config('infrastructure.object_store.disk', 's3'));
        });
    }
    public function boot(): void
    {
        if ($this->app->runningInConsole()) $this->commands([RelayOutboxCommand::class]);
    }
}
