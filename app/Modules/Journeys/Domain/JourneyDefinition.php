<?php

namespace App\Modules\Journeys\Domain;

final readonly class JourneyDefinition
{
    /** @param array<string,mixed> $graph */
    private function __construct(public string $workspaceId, public string $versionId, public array $graph, public string $hash) {}

    /** @param array<string,mixed> $graph */
    public static function publish(string $workspaceId, string $versionId, array $graph, JourneyGraphValidator $validator): self
    {
        $normalized = $validator->normalize($graph);
        return new self($workspaceId, $versionId, $normalized, $validator->hash($normalized));
    }
}
