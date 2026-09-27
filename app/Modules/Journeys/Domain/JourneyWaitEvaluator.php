<?php

namespace App\Modules\Journeys\Domain;

use DateTimeImmutable;

/** Rechecks persisted wait descriptors against predicate state or the bounded deadline. */
final class JourneyWaitEvaluator
{
    /** @param array<string, mixed> $attributes @param array<string, mixed>|null $predicate */
    public function evaluate(DurableJourneyWait $wait, string $workspaceId, DateTimeImmutable $now, array $attributes, ?array $predicate = null): JourneyWaitOutcome
    {
        if ($workspaceId === '' || $wait->workspaceId !== $workspaceId) {
            throw new JourneyDefinitionException('workspace_scope_mismatch', '$.wait.workspace_id');
        }
        if ($predicate !== null) {
            $field = $predicate['field'] ?? null;
            $operator = $predicate['operator'] ?? null;
            if (! is_string($field) || ! is_string($operator)) {
                throw new JourneyDefinitionException('invalid_wait_predicate', '$.wait.predicate');
            }
            $typedOperator = JourneyConditionOperator::tryFrom($operator);
            if ($typedOperator === null) {
                throw new JourneyDefinitionException('invalid_wait_predicate_operator', '$.wait.predicate.operator');
            }
            $hasValue = array_key_exists('value', $predicate);
            if (($typedOperator === JourneyConditionOperator::Exists) === $hasValue) {
                throw new JourneyDefinitionException('wait_predicate_value_mismatch', '$.wait.predicate.value');
            }
            if (in_array($typedOperator, [JourneyConditionOperator::GreaterThan, JourneyConditionOperator::LessThan], true)
                && $hasValue && ! is_numeric($predicate['value'])) {
                throw new JourneyDefinitionException('numeric_wait_predicate_value_required', '$.wait.predicate.value');
            }
            if ($typedOperator === JourneyConditionOperator::Contains && $hasValue && ! is_string($predicate['value'])) {
                throw new JourneyDefinitionException('string_wait_predicate_value_required', '$.wait.predicate.value');
            }
            $condition = (new JourneyConditionEvaluator)->evaluate(
                $attributes,
                $field,
                $typedOperator,
                $predicate['value'] ?? null,
            );
            if ($condition) {
                return JourneyWaitOutcome::Ready;
            }
        }

        return $now->setTimezone($wait->wakeAt->getTimezone()) >= $wait->wakeAt
            ? JourneyWaitOutcome::Ready
            : JourneyWaitOutcome::Waiting;
    }
}
