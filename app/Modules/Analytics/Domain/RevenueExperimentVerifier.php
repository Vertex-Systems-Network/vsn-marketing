<?php

namespace App\Modules\Analytics\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

interface RevenueExperimentVerifier
{
    /** Return only independently verified canonical references, never a causal effect. */
    public function reference(TenantContext $actor, string $contactId, string $exposureId, DateTimeImmutable $conversionAt): ?array;
}
