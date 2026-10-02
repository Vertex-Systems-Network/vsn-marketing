<?php

namespace App\Modules\Experiments\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface ExperimentEligibility
{
    /** Canonical identity, consent, suppression and purpose must be checked independently of variant assignment. */
    public function allows(TenantContext $actor, string $unitKind, string $unitId): bool;
}
