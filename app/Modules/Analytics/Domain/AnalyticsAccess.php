<?php

namespace App\Modules\Analytics\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface AnalyticsAccess
{
    public function allows(TenantContext $actor, string $permission): bool;
}
