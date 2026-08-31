<?php

namespace App\Modules\Providers\Domain\Contracts;

use App\Modules\Providers\Domain\Provider;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\ProviderConnection;
use App\Modules\Providers\Domain\ProviderQuota;

interface ProviderRepository
{
    public function saveProvider(Provider $provider): void;

    public function saveConnection(ProviderConnection $connection): void;

    public function saveCapability(ProviderCapability $capability): void;

    public function saveQuota(ProviderQuota $quota): void;

    public function findProvider(string $workspaceId, string $providerId): ?Provider;

    public function findConnection(string $workspaceId, string $connectionId): ?ProviderConnection;

    public function findCapability(string $workspaceId, string $capabilityId): ?ProviderCapability;

    public function findQuota(string $workspaceId, string $quotaId): ?ProviderQuota;
}
