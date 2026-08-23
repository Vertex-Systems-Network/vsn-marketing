<?php

namespace App\Modules\Core\Domain\Contracts;

use App\Modules\Core\Domain\Idempotency\IdempotencyClaim;

interface IdempotencyRepository
{
    public function claim(string $workspaceId, string $scope, string $key): IdempotencyClaim;

    public function complete(string $workspaceId, string $scope, string $key, array $result): void;

    public function fail(string $workspaceId, string $scope, string $key, string $error): void;
}
