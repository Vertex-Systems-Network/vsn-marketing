<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiCircuitBreaker
{
    public function allows(string $workspaceId, string $routeId): bool;

    public function succeeded(string $workspaceId, string $routeId): void;

    public function failed(string $workspaceId, string $routeId): void;
}
