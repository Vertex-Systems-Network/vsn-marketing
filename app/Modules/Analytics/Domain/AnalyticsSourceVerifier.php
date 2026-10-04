<?php

namespace App\Modules\Analytics\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

/** Independently composed source authority; local matching counts never implement this port. */
interface AnalyticsSourceVerifier
{
    public function verifies(TenantContext $actor, array $checkpoint): bool;
}
