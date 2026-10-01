<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiTelemetryRecorder
{
    /** @param array<string, mixed> $request */
    public function begin(string $workspaceId, string $attemptId, array $route, array $request): bool;

    public function finish(string $workspaceId, string $attemptId, string $status, ?int $costMinor, ?int $inputTokens = null, ?int $outputTokens = null): void;
}
