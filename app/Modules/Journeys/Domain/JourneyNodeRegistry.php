<?php

namespace App\Modules\Journeys\Domain;

/** Registry of executable node configuration contracts. */
final class JourneyNodeRegistry
{
    /** @var array<string, array<string, string>> */
    private const CONFIG_SCHEMAS = [
        'trigger' => ['event' => 'string'],
        'wait' => ['seconds' => 'positive_int'],
        'condition' => ['field' => 'string', 'operator' => 'string', 'value' => 'scalar'],
        'branch' => ['field' => 'string', 'operator' => 'string', 'value' => 'scalar'],
        'action' => ['capability' => 'identifier', 'input' => 'object'],
        'goal' => ['event' => 'string'],
        'exit' => ['event' => 'string'],
        'end' => [],
    ];

    /** @param mixed $input @return array<string, mixed> */
    public function validate(string $type, mixed $input, string $path): array
    {
        $schema = self::CONFIG_SCHEMAS[$type] ?? null;
        if ($schema === null) {
            throw new JourneyDefinitionException('unknown_node_type', $path.'.type');
        }
        if ($input !== null && ! is_array($input)) {
            throw new JourneyDefinitionException('invalid_node_config', $path.'.config');
        }
        $config = $input ?? [];
        if (array_is_list($config) && $config !== []) {
            throw new JourneyDefinitionException('invalid_node_config', $path.'.config');
        }
        foreach ($config as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $schema)) {
                throw new JourneyDefinitionException('unsupported_node_config', $path.'.config.'.$key);
            }
            $valid = $schema[$key] === 'object'
                ? $this->isObjectInput($value)
                : match ($schema[$key]) {
                    'string' => is_string($value) && $value !== '',
                    'identifier' => is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $value) === 1,
                    'positive_int' => is_int($value) && $value > 0 && $value <= 31536000,
                    default => false,
                };
            if ($schema[$key] === 'scalar') {
                $valid = is_scalar($value) || $value === null;
            }
            if (! $valid) {
                throw new JourneyDefinitionException('invalid_node_config', $path.'.config.'.$key);
            }
        }

        if ($type === 'end' && $config !== []) {
            throw new JourneyDefinitionException('unexpected_node_config', $path.'.config');
        }

        return $config;
    }

    private function isObjectInput(mixed $value): bool
    {
        return $value !== [] && ! array_is_list($value);
    }
}
