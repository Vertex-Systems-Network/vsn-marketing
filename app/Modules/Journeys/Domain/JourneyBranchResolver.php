<?php

namespace App\Modules\Journeys\Domain;

/** Resolves one typed branch edge from a boolean condition result. */
final class JourneyBranchResolver
{
    /** @param list<array<string, mixed>> $edges */
    public function resolve(string $fromNode, bool $condition, array $edges): string
    {
        $expected = $condition ? JourneyEdgeType::True->value : JourneyEdgeType::False->value;
        $matches = [];
        foreach ($edges as $edge) {
            if (($edge['from'] ?? null) === $fromNode && ($edge['type'] ?? JourneyEdgeType::Default->value) === $expected) {
                if (! is_string($edge['to'] ?? null) || $edge['to'] === '') {
                    throw new JourneyDefinitionException('invalid_branch_target', '$.edges');
                }
                $matches[] = $edge['to'];
            }
        }
        if (count($matches) !== 1) {
            throw new JourneyDefinitionException(count($matches) === 0 ? 'branch_target_missing' : 'branch_target_ambiguous', '$.edges');
        }

        return $matches[0];
    }
}
