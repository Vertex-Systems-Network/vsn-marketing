<?php

namespace App\Modules\Journeys\Domain;

/** Evaluates only a deliberately small, deterministic scalar condition language. */
final class JourneyConditionEvaluator
{
    /** @param array<string, mixed> $attributes */
    public function evaluate(array $attributes, string $field, JourneyConditionOperator $operator, mixed $expected = null): bool
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,190}$/', $field) !== 1) {
            throw new JourneyDefinitionException('invalid_condition_field', '$.condition.field');
        }
        $exists = array_key_exists($field, $attributes);
        $actual = $attributes[$field] ?? null;

        return match ($operator) {
            JourneyConditionOperator::Exists => $exists,
            JourneyConditionOperator::Equals => $exists && get_debug_type($actual) === get_debug_type($expected) && $actual === $expected,
            JourneyConditionOperator::NotEquals => ! $exists || get_debug_type($actual) !== get_debug_type($expected) || $actual !== $expected,
            JourneyConditionOperator::GreaterThan => $exists && is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            JourneyConditionOperator::LessThan => $exists && is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            JourneyConditionOperator::Contains => $exists && is_string($actual) && is_string($expected) && str_contains($actual, $expected),
        };
    }
}
