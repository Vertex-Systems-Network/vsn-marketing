<?php

namespace App\Modules\Segmentation\Domain;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class SegmentValidator
{
    public function __construct(private SegmentFieldRegistry $fields)
    {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $this->keys($input, ['schema_version', 'subject', 'root'], '$');
        if (($input['schema_version'] ?? null) !== 1 || ($input['subject'] ?? null) !== 'contact') {
            throw new SegmentDefinitionException('unsupported_envelope', '$');
        }
        $state = ['nodes' => 0];
        $root = $this->node($input['root'] ?? null, '$.root', 0, $state);
        if (($root['type'] ?? null) !== 'group') {
            throw new SegmentDefinitionException('root_must_be_group', '$.root');
        }
        return ['schema_version' => 1, 'subject' => 'contact', 'root' => $root];
    }

    /** @param array<string, mixed> $input */
    public function hash(array $input): string
    {
        return hash('sha256', $this->encode($this->normalize($input)));
    }

    /** @param array<string, mixed> $input */
    public function serialize(array $input): string
    {
        return $this->encode($this->normalize($input));
    }

    /** @param array<string, mixed> $input */
    private function encode(array $input): string
    {
        try {
            return json_encode($this->sortKeys($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new SegmentDefinitionException('serialization_failed', '$');
        }
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function node(mixed $node, string $path, int $depth, array &$state): array
    {
        if (! is_array($node) || array_is_list($node)) {
            throw new SegmentDefinitionException('node_must_be_object', $path);
        }
        if ($depth > (int) $this->setting('segmentation.max_depth', 8)) {
            throw new SegmentDefinitionException('maximum_depth_exceeded', $path);
        }
        $state['nodes']++;
        if ($state['nodes'] > (int) $this->setting('segmentation.max_nodes', 100)) {
            throw new SegmentDefinitionException('maximum_nodes_exceeded', $path);
        }
        return match ($node['type'] ?? null) {
            'group' => $this->group($node, $path, $depth, $state),
            'not' => $this->not($node, $path, $depth, $state),
            'attribute' => $this->attribute($node, $path),
            'membership' => $this->membership($node, $path),
            'event' => $this->event($node, $path),
            default => throw new SegmentDefinitionException('unknown_node_type', $path.'.type'),
        };
    }

    private function group(array $node, string $path, int $depth, array &$state): array
    {
        $this->keys($node, ['type', 'operator', 'children'], $path);
        $operator = $node['operator'] ?? null;
        $children = $node['children'] ?? null;
        if (! in_array($operator, ['all', 'any'], true) || ! is_array($children) || ! array_is_list($children) || $children === []) {
            throw new SegmentDefinitionException('invalid_group', $path);
        }
        $normalized = [];
        foreach ($children as $index => $child) {
            $normalized[] = $this->node($child, $path.'.children.'.$index, $depth + 1, $state);
        }
        usort($normalized, fn (array $left, array $right): int => strcmp($this->encode($left), $this->encode($right)));
        return ['type' => 'group', 'operator' => $operator, 'children' => $normalized];
    }

    private function not(array $node, string $path, int $depth, array &$state): array
    {
        $this->keys($node, ['type', 'child'], $path);
        if (! is_array($node['child'] ?? null)) {
            throw new SegmentDefinitionException('invalid_not_child', $path.'.child');
        }
        return ['type' => 'not', 'child' => $this->node($node['child'], $path.'.child', $depth + 1, $state)];
    }

    private function attribute(array $node, string $path): array
    {
        $this->keys($node, ['type', 'field', 'operator', 'value'], $path);
        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        $metadata = is_string($field) ? $this->fields->get($field) : null;
        if ($metadata === null) {
            throw new SegmentDefinitionException('unknown_or_non_targetable_field', $path.'.field');
        }
        $allowed = match ($metadata['type']) {
            'text' => ['equals', 'not_equals', 'is_set', 'is_not_set'],
            'timestamp' => ['before', 'after', 'on_or_before', 'on_or_after', 'is_set', 'is_not_set'],
            default => [],
        };
        if (! in_array($operator, $allowed, true)) {
            throw new SegmentDefinitionException('operator_not_allowed_for_field', $path.'.operator');
        }
        if (in_array($operator, ['is_set', 'is_not_set'], true)) {
            if (array_key_exists('value', $node)) {
                throw new SegmentDefinitionException('unexpected_value', $path.'.value');
            }
            return ['type' => 'attribute', 'field' => $field, 'operator' => $operator];
        }
        $value = $node['value'] ?? null;
        if ($metadata['type'] === 'text' && (! is_string($value) || strlen($value) > 191 || trim($value) === '')) {
            throw new SegmentDefinitionException('invalid_text_value', $path.'.value');
        }
        if ($metadata['type'] === 'timestamp') {
            $value = $this->instant($value, $path.'.value');
        }
        return ['type' => 'attribute', 'field' => $field, 'operator' => $operator, 'value' => $value];
    }

    private function membership(array $node, string $path): array
    {
        $this->keys($node, ['type', 'kind', 'id', 'operator'], $path);
        $kind = $node['kind'] ?? null;
        $id = $node['id'] ?? null;
        $operator = $node['operator'] ?? null;
        if (! in_array($kind, ['list', 'tag'], true) || ! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new SegmentDefinitionException('invalid_membership_reference', $path);
        }
        if (! in_array($operator, ['in', 'not_in'], true)) {
            throw new SegmentDefinitionException('invalid_membership_operator', $path.'.operator');
        }
        return ['type' => 'membership', 'kind' => $kind, 'id' => strtolower($id), 'operator' => $operator];
    }

    private function event(array $node, string $path): array
    {
        $this->keys($node, ['type', 'name', 'mode', 'window', 'minimum'], $path);
        $name = $node['name'] ?? null;
        $mode = $node['mode'] ?? null;
        if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $name)) {
            throw new SegmentDefinitionException('invalid_event_name', $path.'.name');
        }
        if (! in_array($mode, ['exists', 'not_exists', 'count', 'first', 'last'], true)) {
            throw new SegmentDefinitionException('invalid_event_mode', $path.'.mode');
        }
        $window = $this->window($node['window'] ?? null, $path.'.window');
        $normalized = ['type' => 'event', 'name' => $name, 'mode' => $mode, 'window' => $window];
        if ($mode === 'count') {
            $minimum = $node['minimum'] ?? null;
            if (! is_int($minimum) || $minimum < 1 || $minimum > 100) {
                throw new SegmentDefinitionException('invalid_event_count', $path.'.minimum');
            }
            $normalized['minimum'] = $minimum;
        } elseif (array_key_exists('minimum', $node)) {
            throw new SegmentDefinitionException('unexpected_minimum', $path.'.minimum');
        }
        return $normalized;
    }

    private function window(mixed $window, string $path): array
    {
        if (! is_array($window) || array_is_list($window)) {
            throw new SegmentDefinitionException('invalid_window', $path);
        }
        if (($window['kind'] ?? null) === 'relative') {
            $this->keys($window, ['kind', 'days'], $path);
            $days = $window['days'] ?? null;
            if (! is_int($days) || $days < 1 || $days > (int) $this->setting('segmentation.max_event_days', 365)) {
                throw new SegmentDefinitionException('invalid_relative_window', $path.'.days');
            }
            return ['kind' => 'relative', 'days' => $days];
        }
        if (($window['kind'] ?? null) === 'absolute') {
            $this->keys($window, ['kind', 'from', 'to'], $path);
            $from = $this->instant($window['from'] ?? null, $path.'.from');
            $to = $this->instant($window['to'] ?? null, $path.'.to');
            $durationSeconds = (new DateTimeImmutable($to))->getTimestamp()
                - (new DateTimeImmutable($from))->getTimestamp();
            $maximumSeconds = $this->setting('segmentation.max_event_days', 365) * 86400;
            if ($from >= $to || $durationSeconds > $maximumSeconds) {
                throw new SegmentDefinitionException('invalid_absolute_window', $path);
            }
            return ['kind' => 'absolute', 'from' => $from, 'to' => $to];
        }
        throw new SegmentDefinitionException('invalid_window_kind', $path.'.kind');
    }

    private function instant(mixed $value, string $path): string
    {
        if (! is_string($value) || strlen($value) > 40 || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new SegmentDefinitionException('invalid_timestamp', $path);
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            throw new SegmentDefinitionException('invalid_timestamp', $path);
        }
    }

    private function setting(string $key, int $default): int
    {
        if (function_exists('app')) {
            try {
                $application = app();
                if ($application->bound('config')) {
                    return (int) $application->make('config')->get($key, $default);
                }
            } catch (\Throwable) {
                return $default;
            }
        }
        return $default;
    }

    private function keys(array $value, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new SegmentDefinitionException('unknown_key', $path.'.'.(string) reset($unknown));
        }
    }

    private function sortKeys(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortKeys($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
