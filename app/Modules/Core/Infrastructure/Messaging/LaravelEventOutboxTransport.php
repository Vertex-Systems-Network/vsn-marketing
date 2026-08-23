<?php

namespace App\Modules\Core\Infrastructure\Messaging;

use App\Modules\Core\Application\Messaging\OutboxMessagePublished;
use App\Modules\Core\Domain\Contracts\OutboxTransport;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class LaravelEventOutboxTransport implements OutboxTransport
{
    public function __construct(private Dispatcher $events) {}

    public function publish(OutboxMessage $message): void
    {
        $this->events->dispatch(new OutboxMessagePublished($message));
    }
}
