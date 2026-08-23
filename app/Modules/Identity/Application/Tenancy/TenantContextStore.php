<?php

namespace App\Modules\Identity\Application\Tenancy;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use LogicException;

final class TenantContextStore
{
    private ?TenantContext $context = null;

    public function set(TenantContext $context): void
    {
        $this->context = $context;
    }

    public function get(): ?TenantContext
    {
        return $this->context;
    }

    public function require(): TenantContext
    {
        return $this->context ?? throw new LogicException('Tenant context is required but has not been resolved.');
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
