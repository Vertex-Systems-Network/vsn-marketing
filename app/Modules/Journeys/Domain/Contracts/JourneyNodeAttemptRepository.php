<?php

namespace App\Modules\Journeys\Domain\Contracts;

use DateTimeImmutable;
use App\Modules\Journeys\Domain\JourneyAttemptPolicy;

interface JourneyNodeAttemptRepository
{
    /** @return array{id: string, attempt_key: string, lease_token: string, status: string, lease_until: string}|null */
    public function claim(string $workspaceId, string $executionId, string $nodeId, int $attempt, DateTimeImmutable $now, JourneyAttemptPolicy $policy): ?array;

    public function complete(string $workspaceId, string $executionId, string $attemptKey, string $leaseToken, DateTimeImmutable $now): bool;

    /** @param array<string, mixed> $error */
    public function fail(string $workspaceId, string $executionId, string $attemptKey, string $leaseToken, array $error, bool $retryable, bool $outcomeUnknown, JourneyAttemptPolicy $policy, DateTimeImmutable $now): string;

    public function cancelExecution(string $workspaceId, string $executionId, DateTimeImmutable $now): bool;
}
