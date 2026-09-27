<?php

namespace App\Modules\Journeys\Domain;

final readonly class JourneyTransition
{
    public function __construct(public string $workspaceId, public string $executionId, public string $fromNode, public string $toNode, public string $status, public int $revision) {}

    public function idempotencyKey(): string
    {
        return hash('sha256', implode('|', [$this->workspaceId,$this->executionId,$this->fromNode,$this->toNode,(string)$this->revision]));
    }
}
