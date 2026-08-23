<?php

namespace App\Modules\Core\Domain\Contracts;

use App\Modules\Core\Domain\ValueObjects\OutboxEnvelope;

interface TransactionalOutbox
{
    public function record(OutboxEnvelope $message): void;
}
