<?php

namespace App\Modules\Providers\Domain\Connectors\Contracts;

use DateTimeImmutable;

interface WebhookReplayGuard
{
    public function claim(
        string $workspaceId,
        string $connectorKey,
        string $deduplicationKey,
        DateTimeImmutable $receivedAt,
    ): bool;
}
