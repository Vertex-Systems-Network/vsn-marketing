<?php

namespace App\Modules\Core\Application\Messaging;

use App\Modules\Core\Domain\Messaging\OutboxMessage;

final readonly class OutboxMessagePublished
{
    public function __construct(public OutboxMessage $message) {}
}
