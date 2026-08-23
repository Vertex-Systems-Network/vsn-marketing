<?php

namespace App\Modules\Core\Domain\Contracts;

use App\Modules\Core\Domain\Messaging\OutboxMessage;

interface OutboxTransport
{
    public function publish(OutboxMessage $message): void;
}
