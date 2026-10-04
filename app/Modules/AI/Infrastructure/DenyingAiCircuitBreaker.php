<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;

final class DenyingAiCircuitBreaker implements AiCircuitBreaker
{
    public function allows(string $workspaceId, string $routeId): bool
    {
        return false;
    }

    public function succeeded(string $workspaceId, string $routeId): void {}

    public function failed(string $workspaceId, string $routeId): void {}
}
