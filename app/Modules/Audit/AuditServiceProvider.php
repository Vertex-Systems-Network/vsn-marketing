<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Domain\Contracts\AuditEventRepository;
use App\Modules\Audit\Infrastructure\DatabaseAuditEventRepository;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditEventRepository::class, DatabaseAuditEventRepository::class);
    }
}
