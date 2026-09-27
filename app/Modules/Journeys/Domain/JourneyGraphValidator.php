<?php

namespace App\Modules\Journeys\Domain;

use JsonException;

final class JourneyGraphValidator
{
    public const SCHEMA_VERSION = 1;

    public const MAX_NODES = 100;

    public const MAX_DEPTH = 12;

    public const NODE_TYPES = ['trigger', 'wait', 'condition', 'branch', 'action', 'goal', 'exit', 'end'];

    public function __construct(private readonly JourneyNodeRegistry $registry = new JourneyNodeRegistry) {}

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function normalize(array $graph): array
    {
        $this->assertAllowedKeys($graph, ['schema_version', 'nodes', 'edges'], '$');
        if (($graph['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new JourneyDefinitionException('unsupported_schema_version', '$.schema_version');
        }
        if (! isset($graph['nodes']) || ! is_array($graph['nodes']) || ! array_is_list($graph['nodes']) || $graph['nodes'] === []) {
            throw new JourneyDefinitionException('nodes_required', '$.nodes');
        }
        if (count($graph['nodes']) > self::MAX_NODES) {
            throw new JourneyDefinitionException('node_limit_exceeded', '$.nodes');
        }

        $ids = [];
        $nodes = [];
        foreach ($graph['nodes'] as $i => $node) {
            $path = '$.nodes.'.$i;
            if (! is_array($node) || array_is_list($node) || ! is_string($node['id'] ?? null) || $node['id'] === '') {
                throw new JourneyDefinitionException('invalid_node', $path);
            }
            $this->assertAllowedKeys($node, ['id', 'type', 'config'], $path);
            if (isset($ids[$node['id']])) {
                throw new JourneyDefinitionException('duplicate_node_id', $path.'.id');
            }
            $ids[$node['id']] = true;
            if (! in_array($node['type'] ?? null, self::NODE_TYPES, true)) {
                throw new JourneyDefinitionException('unknown_node_type', $path.'.type');
            }
            if (isset($node['config']) && ! is_array($node['config'])) {
                throw new JourneyDefinitionException('invalid_node_config', $path.'.config');
            }

            $normalizedNode = ['id' => $node['id'], 'type' => $node['type']];
            $config = $this->registry->validate($node['type'], $node['config'] ?? null, $path);
            if ($config !== []) {
                $normalizedNode['config'] = $this->normalizeValue($config, $path.'.config', 0);
            }
            $nodes[] = $normalizedNode;
        }

        $edgesInput = $graph['edges'] ?? [];
        if (! is_array($edgesInput) || ! array_is_list($edgesInput)) {
            throw new JourneyDefinitionException('invalid_edges', '$.edges');
        }
        $edges = [];
        $adjacency = array_fill_keys(array_keys($ids), []);
        $seenEdges = [];
        foreach ($edgesInput as $i => $edge) {
            $path = '$.edges.'.$i;
            if (! is_array($edge) || array_is_list($edge)) {
                throw new JourneyDefinitionException('invalid_edge', $path);
            }
            $this->assertAllowedKeys($edge, ['from', 'to', 'type'], $path);
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            if (! is_string($from) || ! is_string($to) || ! isset($ids[$from], $ids[$to])) {
                throw new JourneyDefinitionException('edge_references_unknown_node', $path);
            }
            if ($from === $to) {
                throw new JourneyDefinitionException('self_loop_forbidden', $path);
            }
            if (isset($edge['type']) && (! is_string($edge['type']) || $edge['type'] === '')) {
                throw new JourneyDefinitionException('invalid_edge_type', $path.'.type');
            }

            $normalizedEdge = ['from' => $from, 'to' => $to];
            if (array_key_exists('type', $edge)) {
                $normalizedEdge['type'] = $edge['type'];
            }
            $identity = json_encode($normalizedEdge, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (isset($seenEdges[$identity])) {
                throw new JourneyDefinitionException('duplicate_edge', $path);
            }
            $seenEdges[$identity] = true;
            $edges[] = $normalizedEdge;
            $adjacency[$from][] = $to;
        }

        $this->assertAcyclicAndBounded($adjacency);
        usort($nodes, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        usort($edges, static fn (array $a, array $b): int => [$a['from'], $a['to'], $a['type'] ?? ''] <=> [$b['from'], $b['to'], $b['type'] ?? '']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     *
     * @throws JsonException
     */
    public function hash(array $graph): string
    {
        return hash('sha256', json_encode($this->normalize($graph), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function assertAllowedKeys(array $value, array $allowed, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new JourneyDefinitionException('unsupported_field', $path.'.'.$key);
            }
        }
    }

    private function normalizeValue(mixed $value, string $path, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new JourneyDefinitionException('config_depth_exceeded', $path);
        }
        if (! is_array($value)) {
            if (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new JourneyDefinitionException('invalid_config_value', $path);
            }
            if (is_float($value) && ! is_finite($value)) {
                throw new JourneyDefinitionException('invalid_config_value', $path);
            }

            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeValue($item, $path.'.'.$key, $depth + 1);
        }
        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     */
    private function assertAcyclicAndBounded(array $adjacency): void
    {
        $visiting = [];
        $memoizedDepth = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$memoizedDepth, $adjacency): int {
            if (isset($visiting[$node])) {
                throw new JourneyDefinitionException('cycle_forbidden', '$.edges');
            }
            if (isset($memoizedDepth[$node])) {
                return $memoizedDepth[$node];
            }

            $visiting[$node] = true;
            $depth = 1;
            foreach ($adjacency[$node] as $next) {
                $depth = max($depth, 1 + $visit($next));
                if ($depth > self::MAX_DEPTH) {
                    throw new JourneyDefinitionException('graph_depth_exceeded', '$.edges');
                }
            }
            unset($visiting[$node]);

            return $memoizedDepth[$node] = $depth;
        };

        foreach (array_keys($adjacency) as $node) {
            $visit($node);
        }
    }
}
