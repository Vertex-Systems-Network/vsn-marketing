<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;

final class DenyingAiTelemetryRecorder implements AiTelemetryRecorder
{
    public function begin(string $workspaceId, string $attemptId, array $route, array $request): bool
    {
        return false;
    }

    public function finish(string $workspaceId, string $attemptId, string $status, ?int $costMinor, ?int $inputTokens = null, ?int $outputTokens = null): void {}
}
