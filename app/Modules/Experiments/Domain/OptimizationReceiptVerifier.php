<?php

namespace App\Modules\Experiments\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;

/** A separately trusted gateway receipt and context source authorization. No default adapter. */
interface OptimizationReceiptVerifier
{
    /** @param list<string> $sourceIds */
    public function verified(TenantContext $actor, string $traceId, string $promptHash, string $contextHash, array $sourceIds, int $actualMinor): bool;
}
