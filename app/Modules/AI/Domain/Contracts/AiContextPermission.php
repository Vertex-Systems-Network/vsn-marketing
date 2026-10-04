<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiContextPermission
{
    public function allows(TenantContext $scope, string $permission): bool;
}
