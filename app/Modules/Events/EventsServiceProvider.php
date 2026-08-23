<?php

namespace App\Modules\Events;

use App\Modules\Core\Application\Messaging\OutboxMessagePublished;
use App\Modules\Events\Application\CanonicalEventOutboxListener;
use App\Modules\Events\Domain\Contracts\CustomerEventRepository;
use App\Modules\Events\Domain\Contracts\CustomerEventSubjectResolver;
use App\Modules\Events\Domain\Contracts\EventTransaction;
use App\Modules\Events\Domain\Contracts\EventTypeRepository;
use App\Modules\Events\Infrastructure\DatabaseCustomerEventRepository;
use App\Modules\Events\Infrastructure\DatabaseCustomerEventSubjectResolver;
use App\Modules\Events\Infrastructure\DatabaseEventTransaction;
use App\Modules\Events\Infrastructure\DatabaseEventTypeRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventTypeRepository::class, DatabaseEventTypeRepository::class);
        $this->app->singleton(CustomerEventRepository::class, DatabaseCustomerEventRepository::class);
        $this->app->singleton(CustomerEventSubjectResolver::class, DatabaseCustomerEventSubjectResolver::class);
        $this->app->singleton(EventTransaction::class, DatabaseEventTransaction::class);
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(OutboxMessagePublished::class, CanonicalEventOutboxListener::class);
    }
}
