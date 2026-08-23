<?php

namespace App\Modules\Events;

use App\Modules\Core\Application\Messaging\OutboxMessagePublished;
use App\Modules\Events\Application\CanonicalEventOutboxListener;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

final class EventsServiceProvider extends ServiceProvider
{
    public function boot(Dispatcher $events): void
    {
        $events->listen(OutboxMessagePublished::class, CanonicalEventOutboxListener::class);
    }
}
