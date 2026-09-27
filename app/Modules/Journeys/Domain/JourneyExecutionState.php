<?php

namespace App\Modules\Journeys\Domain;

final class JourneyExecutionState
{
    public function __construct(public readonly string $workspaceId, public readonly string $executionId, public string $status = 'queued', public int $revision = 0) {}

    public function transition(string $next): void
    {
        $allowed = ['queued'=>['waiting','running','cancelled'], 'waiting'=>['running','cancelled','exited'], 'running'=>['succeeded','waiting','blocked','failed','cancelled','exited'], 'blocked'=>['running','cancelled'], 'failed'=>['running','cancelled'], 'succeeded'=>[], 'cancelled'=>[], 'exited'=>[]];
        if (! in_array($next, $allowed[$this->status] ?? [], true)) throw new JourneyDefinitionException('invalid_execution_transition', '$.status');
        $this->status = $next; $this->revision++;
    }
}
