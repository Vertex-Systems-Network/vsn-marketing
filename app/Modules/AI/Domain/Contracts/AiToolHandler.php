<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiToolHandler
{
    public function execute(TenantContext $scope, array $arguments): array;
}
