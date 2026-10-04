<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AiPromptPromotionAuthority
{
    /** Verify a current independent human review, permission and exact candidate/report/scope binding. */
    public function reviewer(TenantContext $scope, string $candidateHash, string $reportHash): ?string;
}
