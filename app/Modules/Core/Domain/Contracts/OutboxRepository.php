<?php

namespace App\Modules\Core\Domain\Contracts;

use App\Modules\Core\Domain\Messaging\OutboxMessage;

interface OutboxRepository
{
    public function store(OutboxMessage $message): void;

    public function findPending(string $id): ?OutboxMessage;

    /** @return list<string> */
    public function pendingIds(int $limit): array;

    public function markPublished(string $id): void;

    /** @param list<int> $backoffSeconds */
    public function markAttemptFailed(string $id, string $error, int $maxAttempts, array $backoffSeconds): void;

    public function replayDeadLetter(string $id): bool;
}
