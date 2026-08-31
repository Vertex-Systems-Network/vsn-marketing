<?php

namespace App\Modules\Providers\Domain\Connectors\Contracts;

use App\Modules\Providers\Domain\Connectors\ConnectorManifest;

interface ConnectorAdapter
{
    public function manifest(): ConnectorManifest;
}
