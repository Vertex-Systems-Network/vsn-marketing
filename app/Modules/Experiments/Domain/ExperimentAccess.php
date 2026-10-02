<?php

namespace App\Modules\Experiments\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface ExperimentAccess
{
    public function allows(TenantContext $actor, string $permission): bool;
}
