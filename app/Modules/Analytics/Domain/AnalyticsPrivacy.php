<?php

namespace App\Modules\Analytics\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

interface AnalyticsPrivacy
{
    /** Null means purpose or retention is not approved. */
    public function retentionDays(TenantContext $actor): ?int;

    public function permits(TenantContext $actor, string $contactId, DateTimeImmutable $occurredAt): bool;
}
