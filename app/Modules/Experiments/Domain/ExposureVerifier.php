<?php

namespace App\Modules\Experiments\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;

interface ExposureVerifier
{
    public function witnessed(TenantContext $actor, string $experimentId, string $assignmentId, string $variant, string $reference, DateTimeImmutable $at): bool;
}
