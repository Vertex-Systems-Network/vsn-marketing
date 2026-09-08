<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryFairnessDecision
{
    public function __construct(
        public string $workspaceId,
        public bool $admitted,
        public int $workspaceShare,
        public int $workspaceInFlight,
        public int $globalInFlight,
        public ?string $reason,
    ) {}
}
