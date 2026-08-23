<?php

namespace App\Modules\Identity\Application\Tenancy;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Closure;

final readonly class UseTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(object $job, Closure $next): mixed
    {
        $store = app(TenantContextStore::class);
        $store->set($this->context);

        try {
            return $next($job);
        } finally {
            $store->clear();
        }
    }
}
