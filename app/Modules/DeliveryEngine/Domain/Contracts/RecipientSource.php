<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\RecipientIdentity;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

interface RecipientSource
{
    public function resolve(
        TenantContext $context,
        string $contactId,
        string $contactIdentityId,
        DeliveryChannel $channel,
    ): RecipientIdentity;
}
