<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiToolApproval
{
    public function approved(TenantContext $scope, string $toolId, string $argumentsHash, ?string $reference): bool;
}
