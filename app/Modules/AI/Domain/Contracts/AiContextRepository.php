<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

interface AiContextRepository
{
    /**
     * Return only records matching every provided scope dimension, including null dimensions.
     *
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    public function fetch(TenantContext $scope, ?string $customerId, ?string $runId, array $sourceIds, DateTimeImmutable $at): array;

    public function delete(TenantContext $scope, ?string $customerId, ?string $runId, string $sourceId): bool;
}
