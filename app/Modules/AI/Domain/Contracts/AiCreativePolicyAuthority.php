<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiCreativePolicyAuthority
{
    /** Verify current scoped input rights/consent/brand policy for this exact request. */
    public function allowsInput(TenantContext $scope, string $requestHash, string $rightsReference): bool;

    /** Current independently verified rights/brand/safety human reviews, bound to exact bytes and scope. */
    public function reviewers(TenantContext $scope, string $candidateHash): array;
}
