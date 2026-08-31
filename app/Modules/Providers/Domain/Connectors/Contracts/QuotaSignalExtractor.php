<?php

namespace App\Modules\Providers\Domain\Connectors\Contracts;

use App\Modules\Providers\Domain\Connectors\ProviderQuotaSignal;
use App\Modules\Providers\Domain\Connectors\ProviderResponseEvidence;

interface QuotaSignalExtractor
{
    /** @return list<ProviderQuotaSignal> */
    public function extract(ProviderResponseEvidence $response, string $operation): array;
}
