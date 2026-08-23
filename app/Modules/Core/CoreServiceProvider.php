<?php

namespace App\Modules\Core;

use App\Modules\Core\Application\Messaging\DispatchPendingOutbox;
use App\Modules\Core\Application\Messaging\ReplayDeadLetteredOutbox;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\DistributedLock;
use App\Modules\Core\Domain\Contracts\IdempotencyRepository;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Core\Domain\Contracts\ObjectStore;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Contracts\OutboxTransport;
use App\Modules\Core\Infrastructure\Identifiers\UuidIdentifierGenerator;
use App\Modules\Core\Infrastructure\Idempotency\DatabaseIdempotencyRepository;
use App\Modules\Core\Infrastructure\Locks\RedisDistributedLock;
use App\Modules\Core\Infrastructure\Messaging\DatabaseOutboxRepository;
use App\Modules\Core\Infrastructure\Messaging\LaravelEventOutboxTransport;
use App\Modules\Core\Infrastructure\Storage\LaravelObjectStore;
use App\Modules\Core\Infrastructure\Time\SystemClock;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(IdentifierGenerator::class, UuidIdentifierGenerator::class);
        $this->app->singleton(DistributedLock::class, RedisDistributedLock::class);
        $this->app->singleton(IdempotencyRepository::class, DatabaseIdempotencyRepository::class);
        $this->app->singleton(OutboxRepository::class, DatabaseOutboxRepository::class);
        $this->app->singleton(OutboxTransport::class, LaravelEventOutboxTransport::class);

        $this->app->singleton(ObjectStore::class, function ($app): ObjectStore {
            return new LaravelObjectStore(
                filesystems: $app->make(FilesystemManager::class),
                disk: (string) config('infrastructure.object_store.disk', 's3'),
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchPendingOutbox::class,
                ReplayDeadLetteredOutbox::class,
            ]);
        }
    }
}
