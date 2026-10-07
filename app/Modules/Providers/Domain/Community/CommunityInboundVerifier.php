<?php

namespace App\Modules\Providers\Domain\Community;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface CommunityInboundVerifier
{
    public function verify(TenantContext $actor, CommunityItem $item): ?string;
}
