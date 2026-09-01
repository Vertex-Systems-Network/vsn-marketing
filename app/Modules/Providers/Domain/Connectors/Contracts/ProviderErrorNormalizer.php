<?php

namespace App\Modules\Providers\Domain\Connectors\Contracts;

use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderFailureEvidence;

interface ProviderErrorNormalizer
{
    public function normalize(ProviderFailureEvidence $failure): NormalizedProviderError;
}
