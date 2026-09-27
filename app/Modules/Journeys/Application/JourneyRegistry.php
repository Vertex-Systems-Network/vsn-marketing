<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyDefinition;
use App\Modules\Journeys\Domain\JourneyGraphValidator;

final readonly class JourneyRegistry
{
    public function __construct(private JourneyGraphValidator $validator) {}

    /** @param array<string,mixed> $graph @return array{definition:JourneyDefinition, enrollment_key:string} */
    public function publish(string $workspaceId, string $versionId, array $graph, string $subjectId, string $enrollmentId): array
    {
        $definition = JourneyDefinition::publish($workspaceId, $versionId, $graph, $this->validator);
        return ['definition'=>$definition, 'enrollment_key'=>hash('sha256', implode('|', [$workspaceId, $versionId, $subjectId, $enrollmentId]))];
    }
}
