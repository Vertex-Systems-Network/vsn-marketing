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
            $condition = (new JourneyConditionEvaluator)->evaluate(
                $attributes,
                $field,
                JourneyConditionOperator::tryFrom($operator) ?? throw new JourneyDefinitionException('invalid_wait_predicate_operator', '$.wait.predicate.operator'),
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
