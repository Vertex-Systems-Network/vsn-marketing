<?php

namespace App\Modules\Journeys\Domain;

/** Registry of executable node configuration contracts. */
final class JourneyNodeRegistry
{
    /** @var array<string, array<string, string>> */
    private const CONFIG_SCHEMAS = [
        'trigger' => ['event' => 'string'],
        'wait' => ['seconds' => 'positive_int', 'field' => 'string', 'operator' => 'condition_operator', 'value' => 'scalar'],
        'condition' => ['field' => 'string', 'operator' => 'condition_operator', 'value' => 'scalar'],
        'branch' => ['field' => 'string', 'operator' => 'condition_operator', 'value' => 'scalar'],
        'action' => ['capability' => 'identifier', 'input' => 'object'],
        'goal' => ['event' => 'string'],
        'exit' => ['event' => 'string'],
        'end' => [],
    ];

    /** @var array<string, list<string>> */
    private const REQUIRED_CONFIG_KEYS = [
        'trigger' => ['event'],
        'wait' => ['seconds'],
        'condition' => ['field', 'operator'],
        'branch' => ['field', 'operator'],
        'action' => ['capability'],
        'goal' => ['event'],
        'exit' => ['event'],
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
        foreach (self::REQUIRED_CONFIG_KEYS[$type] as $requiredKey) {
            if (! array_key_exists($requiredKey, $config)) {
                throw new JourneyDefinitionException('required_node_config_missing', $path.'.config.'.$requiredKey);
            }
        }
        foreach ($config as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $schema)) {
                throw new JourneyDefinitionException('unsupported_node_config', $path.'.config.'.$key);
            }
            $valid = $schema[$key] === 'object'
                ? $this->isObjectInput($value)
                : match ($schema[$key]) {
                    'string' => is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $value) === 1,
                    'identifier' => is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $value) === 1,
                    'positive_int' => is_int($value) && $value > 0 && $value <= 31536000,
                    'condition_operator' => is_string($value) && JourneyConditionOperator::tryFrom($value) !== null,
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
        $hasCondition = in_array($type, ['condition', 'branch'], true)
            || ($type === 'wait' && (array_key_exists('field', $config) || array_key_exists('operator', $config) || array_key_exists('value', $config)));
        if ($hasCondition) {
            if (! isset($config['field'], $config['operator'])) {
                throw new JourneyDefinitionException('condition_definition_incomplete', $path.'.config');
            }
            $operator = JourneyConditionOperator::from($config['operator']);
            $hasValue = array_key_exists('value', $config);
            if (($operator === JourneyConditionOperator::Exists) === $hasValue) {
                throw new JourneyDefinitionException('condition_value_mismatch', $path.'.config.value');
            }
            if (in_array($operator, [JourneyConditionOperator::GreaterThan, JourneyConditionOperator::LessThan], true)
                && $hasValue && ! is_numeric($config['value'])) {
                throw new JourneyDefinitionException('numeric_condition_value_required', $path.'.config.value');
            }
            if ($operator === JourneyConditionOperator::Contains && $hasValue && ! is_string($config['value'])) {
                throw new JourneyDefinitionException('string_condition_value_required', $path.'.config.value');
            }
        }

        return $config;
    }

    private function isObjectInput(mixed $value): bool
    {
        return $value !== [] && ! array_is_list($value);
    }
}
