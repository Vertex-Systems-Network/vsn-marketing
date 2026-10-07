<?php

namespace App\Modules\Analytics\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Analytics\EngagementFact;

interface ProviderEngagementSourceVerifier
{
    /**
     * Return a provider-verified stable source reference, or null when the fact cannot be verified.
     * The returned reference is hashed before persistence and must not contain credentials.
     */
    public function verify(TenantContext $actor, EngagementFact $fact): ?string;
}
