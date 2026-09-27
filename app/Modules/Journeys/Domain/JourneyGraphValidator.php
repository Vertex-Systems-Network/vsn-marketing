<?php

namespace App\Modules\Journeys\Domain;

final class JourneyGraphValidator
{
    public const SCHEMA_VERSION = 1;

    public const MAX_NODES = 100;

    public const MAX_DEPTH = 12;

    public const NODE_TYPES = ['trigger', 'wait', 'condition', 'branch', 'action', 'goal', 'exit', 'end'];

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function normalize(array $graph): array
    {
        if (($graph['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new JourneyDefinitionException('unsupported_schema_version', '$.schema_version');
        }
        if (! isset($graph['nodes']) || ! is_array($graph['nodes']) || $graph['nodes'] === []) {
            throw new JourneyDefinitionException('nodes_required', '$.nodes');
        }
        if (count($graph['nodes']) > self::MAX_NODES) {
            throw new JourneyDefinitionException('node_limit_exceeded', '$.nodes');
        }
        $ids = [];
        foreach ($graph['nodes'] as $i => $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || $node['id'] === '') {
                throw new JourneyDefinitionException('invalid_node', '$.nodes.'.$i);
            }
            if (isset($ids[$node['id']])) {
                throw new JourneyDefinitionException('duplicate_node_id', '$.nodes.'.$i.'.id');
            }
            $ids[$node['id']] = true;
            if (! in_array($node['type'] ?? null, self::NODE_TYPES, true)) {
                throw new JourneyDefinitionException('unknown_node_type', '$.nodes.'.$i.'.type');
            }
            if (isset($node['config']) && ! is_array($node['config'])) {
                throw new JourneyDefinitionException('invalid_node_config', '$.nodes.'.$i.'.config');
            }
            if (isset($node['code']) || isset($node['sql']) || isset($node['expression'])) {
                throw new JourneyDefinitionException('executable_text_forbidden', '$.nodes.'.$i);
            }
        }
        foreach (($graph['edges'] ?? []) as $i => $edge) {
            if (! is_array($edge) || ! isset($ids[$edge['from'] ?? ''], $ids[$edge['to'] ?? ''])) {
                throw new JourneyDefinitionException('edge_references_unknown_node', '$.edges.'.$i);
            }
            if (($edge['from'] ?? null) === ($edge['to'] ?? null)) {
                throw new JourneyDefinitionException('self_loop_forbidden', '$.edges.'.$i);
            }
        }
        $canonical = [
            'schema_version' => self::SCHEMA_VERSION,
            'nodes' => array_values($graph['nodes']),
            'edges' => array_values($graph['edges'] ?? []),
        ];
        usort($canonical['nodes'], fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        usort($canonical['edges'], fn (array $a, array $b): int => (($a['from'] ?? '').($a['to'] ?? '')) <=> (($b['from'] ?? '').($b['to'] ?? '')));

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    public function hash(array $graph): string
    {
        return hash('sha256', json_encode($this->normalize($graph), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
