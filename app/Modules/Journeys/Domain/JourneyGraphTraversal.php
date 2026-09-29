<?php

namespace App\Modules\Journeys\Domain;

/** Resolves the pinned graph one node at a time; a worker owns persistence and side effects. */
final readonly class JourneyGraphTraversal
{
    public function __construct(
        private JourneyGraphValidator $validator = new JourneyGraphValidator,
        private JourneyConditionEvaluator $conditions = new JourneyConditionEvaluator,
    ) {}

    /** @param array<string, mixed> $graph */
    public function entry(array $graph, string $eventType): string
    {
        $normalized = $this->validator->normalize($graph);
        $matches = array_values(array_filter($normalized['nodes'], static fn (array $node): bool =>
            $node['type'] === 'trigger' && ($node['config']['event'] ?? null) === $eventType
        ));
        if (count($matches) !== 1) {
            throw new JourneyDefinitionException('trigger_entry_ambiguous_or_missing', '$.nodes');
        }

        return $matches[0]['id'];
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    public function successors(array $graph, string $nodeId, array $attributes = []): array
    {
        $normalized = $this->validator->normalize($graph);
        $node = null;
        foreach ($normalized['nodes'] as $candidate) {
            if ($candidate['id'] === $nodeId) {
                $node = $candidate;
                break;
            }
        }
        if ($node === null) {
            throw new JourneyDefinitionException('unknown_current_node', '$.nodes');
        }

        $outgoing = array_values(array_filter($normalized['edges'], static fn (array $edge): bool => $edge['from'] === $nodeId));
        if (in_array($node['type'], ['end', 'goal', 'exit'], true)) {
            if ($outgoing !== []) {
                throw new JourneyDefinitionException('terminal_node_has_edges', '$.edges');
            }

            return [];
        }

        if (in_array($node['type'], ['branch', 'condition'], true)) {
            $config = $node['config'];
            $result = $this->conditions->evaluate(
                $attributes,
                $config['field'],
                JourneyConditionOperator::from($config['operator']),
                $config['value'] ?? null,
            );
            $expected = $result ? JourneyEdgeType::True->value : JourneyEdgeType::False->value;
            $matches = array_values(array_filter($outgoing, static fn (array $edge): bool => ($edge['type'] ?? 'default') === $expected));
            $types = array_map(static fn (array $edge): string => $edge['type'] ?? 'default', $outgoing);
            sort($types);
            if ($types !== ['false', 'true'] || count($matches) !== 1) {
                throw new JourneyDefinitionException('branch_edges_invalid', '$.edges');
            }

            return [$matches[0]['to']];
        }

        if ($outgoing === [] || count(array_filter($outgoing, static fn (array $edge): bool => ($edge['type'] ?? 'default') !== 'default')) > 0) {
            throw new JourneyDefinitionException('default_edges_required', '$.edges');
        }

        return array_values(array_column($outgoing, 'to'));
    }
}
