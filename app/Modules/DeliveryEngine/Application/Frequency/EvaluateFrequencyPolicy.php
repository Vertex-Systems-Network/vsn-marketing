<?php

namespace App\Modules\DeliveryEngine\Application\Frequency;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyCounterSnapshot;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyPolicy;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use DateTimeImmutable;
use InvalidArgumentException;

final class EvaluateFrequencyPolicy
{
    public function evaluate(
        string $workspaceId,
        MessageIntentType $messagePurpose,
        string $recipientScope,
        string $operationKey,
        ?FrequencyPolicy $policy,
        ?FrequencyCounterSnapshot $counter,
        DateTimeImmutable $evaluatedAt,
    ): FrequencyEvaluationResult {
        foreach ([
            'workspaceId' => $workspaceId,
            'recipientScope' => $recipientScope,
            'operationKey' => $operationKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($policy === null) {
            return $this->result(EligibilityOutcome::Review, ['frequency_policy_missing'], $evaluatedAt);
        }

        if ($policy->workspaceId !== $workspaceId) {
            return $this->result(EligibilityOutcome::Deny, ['frequency_policy_workspace_mismatch'], $evaluatedAt);
        }

        if ($policy->messagePurpose !== $messagePurpose || $policy->recipientScope !== $recipientScope) {
            return $this->result(EligibilityOutcome::Deny, ['frequency_policy_scope_mismatch'], $evaluatedAt);
        }

        if ($evaluatedAt < $policy->effectiveAt) {
            return $this->result(EligibilityOutcome::Review, ['frequency_policy_not_yet_effective'], $evaluatedAt);
        }

        $window = $policy->windowFor($evaluatedAt);

        if ($counter === null) {
            return $this->result(
                EligibilityOutcome::Review,
                ['frequency_counter_missing'],
                $evaluatedAt,
                windowStart: $window['start'],
                windowEnd: $window['end'],
            );
        }

        if (
            $counter->workspaceId !== $workspaceId
            || $counter->policyId !== $policy->id
            || $counter->recipientScope !== $recipientScope
        ) {
            return $this->result(
                EligibilityOutcome::Deny,
                ['frequency_counter_scope_mismatch'],
                $evaluatedAt,
                currentCount: $counter->count,
                windowStart: $window['start'],
                windowEnd: $window['end'],
            );
        }

        if (
            $counter->windowStart->getTimestamp() !== $window['start']->getTimestamp()
            || $counter->windowEnd->getTimestamp() !== $window['end']->getTimestamp()
        ) {
            return $this->result(
                EligibilityOutcome::Review,
                ['frequency_counter_window_mismatch'],
                $evaluatedAt,
                currentCount: $counter->count,
                windowStart: $window['start'],
                windowEnd: $window['end'],
            );
        }

        if ($counter->observedAt > $evaluatedAt) {
            return $this->result(
                EligibilityOutcome::Review,
                ['frequency_counter_observed_in_future'],
                $evaluatedAt,
                currentCount: $counter->count,
                windowStart: $window['start'],
                windowEnd: $window['end'],
            );
        }

        if ($counter->hasCounted($operationKey)) {
            return $this->result(
                EligibilityOutcome::Allow,
                ['frequency_operation_already_counted'],
                $evaluatedAt,
                replay: true,
                currentCount: $counter->count,
                nextCount: $counter->count,
                windowStart: $window['start'],
                windowEnd: $window['end'],
            );
        }

        if ($counter->count >= $policy->maxMessages) {
            return $this->result(
                EligibilityOutcome::Deny,
                ['frequency_limit_reached'],
                $evaluatedAt,
                currentCount: $counter->count,
                nextCount: $counter->count,
                windowStart: $window['start'],
                windowEnd: $window['end'],
            );
        }

        return $this->result(
            EligibilityOutcome::Allow,
            ['frequency_capacity_available'],
            $evaluatedAt,
            currentCount: $counter->count,
            nextCount: $counter->count + 1,
            windowStart: $window['start'],
            windowEnd: $window['end'],
        );
    }

    /** @param list<string> $reasons */
    private function result(
        EligibilityOutcome $outcome,
        array $reasons,
        DateTimeImmutable $evaluatedAt,
        bool $replay = false,
        ?int $currentCount = null,
        ?int $nextCount = null,
        ?DateTimeImmutable $windowStart = null,
        ?DateTimeImmutable $windowEnd = null,
    ): FrequencyEvaluationResult {
        return new FrequencyEvaluationResult(
            outcome: $outcome,
            reasons: $reasons,
            evaluatedAt: $evaluatedAt,
            replay: $replay,
            currentCount: $currentCount,
            nextCount: $nextCount,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
        );
    }
}
