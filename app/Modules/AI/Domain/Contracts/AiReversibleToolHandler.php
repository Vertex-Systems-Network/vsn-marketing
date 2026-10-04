<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiReversibleToolHandler extends AiToolHandler
{
    public function preflight(TenantContext $scope, array $arguments): bool;

    public function postcondition(TenantContext $scope, array $arguments, array $result): bool;

    public function rollback(TenantContext $scope, array $arguments): void;
}
