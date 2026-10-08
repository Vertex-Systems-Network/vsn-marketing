<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use InvalidArgumentException;

final readonly class ConnectorCandidateCanaryPolicy
{
    public function __construct(
        public bool $enabled = false,
        public int $maxExposurePercent = 0,
        public int $maxTenants = 0,
        public int $maxDurationMinutes = 0,
        public bool $reversible = true,
    ) {
        if ($maxExposurePercent < 0 || $maxExposurePercent > 5
            || $maxTenants < 0 || $maxTenants > 10
            || $maxDurationMinutes < 0 || $maxDurationMinutes > 1440
            || $reversible === false
            || ($enabled && ($maxExposurePercent === 0 || $maxTenants === 0 || $maxDurationMinutes === 0))) {
            throw new InvalidArgumentException('Canary policy must remain reversible and within its strict exposure, tenant and duration bounds.');
        }
    }
}
