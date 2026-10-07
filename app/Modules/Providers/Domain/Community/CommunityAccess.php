<?php

namespace App\Modules\Providers\Domain\Community;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface CommunityAccess
{
    public function allows(TenantContext $actor, string $permission): bool;
}
