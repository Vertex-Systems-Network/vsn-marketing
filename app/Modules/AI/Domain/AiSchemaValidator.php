<?php

namespace App\Modules\AI\Domain;

use InvalidArgumentException;

/** A bounded strict schema dialect. Unsupported keywords fail closed. */
final class AiSchemaValidator
{
    public function validate(mixed $value, array $schema, int $depth = 0): void
    {
        if ($depth > 8 || array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'maxLength', 'maxItems', 'minimum', 'maximum']) !== []) {
            throw new InvalidArgumentException('Unsupported AI schema.');
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (! $valid || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            throw new InvalidArgumentException('AI schema value rejected.');
        }
        if ($type === 'object') {
            $properties = $schema['properties'] ?? null;
            $required = $schema['required'] ?? null;
            if (! is_array($properties) || ! is_array($required) || ($schema['additionalProperties'] ?? null) !== false
                || array_diff(array_keys($value), array_keys($properties)) !== [] || array_diff($required, array_keys($value)) !== []) {
                throw new InvalidArgumentException('AI object fields rejected.');
            }
            foreach ($value as $key => $item) {
                $this->validate($item, $properties[$key], $depth + 1);
            }
        } elseif ($type === 'array') {
            if (! is_int($schema['maxItems'] ?? null) || $schema['maxItems'] < 0 || $schema['maxItems'] > 32
                || count($value) > $schema['maxItems'] || ! is_array($schema['items'] ?? null)) {
                throw new InvalidArgumentException('AI array bounds rejected.');
            }
            foreach ($value as $item) {
                $this->validate($item, $schema['items'], $depth + 1);
            }
        } elseif ($type === 'string') {
            if (! is_int($schema['maxLength'] ?? null) || $schema['maxLength'] < 1 || $schema['maxLength'] > 4096
                || $value === '' || strlen($value) > $schema['maxLength'] || ! (new AiContextSanitizer)->safe($value)) {
                throw new InvalidArgumentException('AI text bounds or data policy rejected.');
            }
        } elseif ($type === 'integer') {
            if (! is_int($schema['minimum'] ?? null) || ! is_int($schema['maximum'] ?? null)
                || $value < $schema['minimum'] || $value > $schema['maximum']) {
                throw new InvalidArgumentException('AI numeric bounds rejected.');
            }
        }
    }
}
