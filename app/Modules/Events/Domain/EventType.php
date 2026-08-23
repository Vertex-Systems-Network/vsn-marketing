<?php

namespace App\Modules\Events\Domain;

final readonly class EventType
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $canonicalName,
        public int $schemaVersion,
    ) {}
}
