<?php

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Events\Domain\EventType;

interface EventTypeRepository
{
    public function ensure(string $workspaceId, string $canonicalName, int $schemaVersion): EventType;
}
