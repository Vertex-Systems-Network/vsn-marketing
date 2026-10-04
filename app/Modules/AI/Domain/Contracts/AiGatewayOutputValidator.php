<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiGatewayOutputValidator
{
    public function validate(array $result, array $request, TenantContext $scope): array;
}
