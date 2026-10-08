<?php

namespace App\Modules\Providers\Domain\ConnectorFactory\Contracts;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleHealth;

interface ConnectorLifecycleHealthRepository
{
    public function record(ConnectorLifecycleHealth $health): ConnectorLifecycleHealth;

    public function findByReconciliationKey(string $workspaceId, string $providerKey, string $reconciliationKey): ?ConnectorLifecycleHealth;

    public function findLatestForProvider(string $workspaceId, string $providerKey): ?ConnectorLifecycleHealth;
}
