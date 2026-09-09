<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRecoveryRepository;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryTransaction;
use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryResult;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryPolicy;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final readonly class RecoverDeliveryOperation
{
    public const AUDIT_ACTION = 'delivery.operation.recovery_recorded';

    public function __construct(
        private Clock $clock,
        private DeliveryRecoveryRepository $repository,
        private DeliveryTransaction $transaction,
        private DeliveryRetryPolicy $retryPolicy,
        private DeliveryCircuitBreakerPolicy $breakerPolicy,
        private DeliveryDeadLetterPolicy $deadLetterPolicy,
        private DeliveryAdmissionCoordinator $coordinator,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        TenantContext $context,
        string $operationId,
        string $attemptId,
        DeliveryFailureObservation $observation,
        bool $operationExpired = false,
        bool $invariantCorruption = false,
    ): DeliveryRecoveryResult {
        $observedAt = $this->clock->now();

        $result = $this->transaction->run(function () use (
            $context,
            $operationId,
            $attemptId,
            $observation,
            $operationExpired,
            $invariantCorruption,
            $observedAt,
        ): DeliveryRecoveryResult {
            $snapshot = $this->repository->lockSnapshot(
                workspaceId: $context->workspaceId,
                operationId: $operationId,
            );

            if ($snapshot === null || $snapshot->operation->workspaceId !== $context->workspaceId) {
                throw new AuthorizationException('Delivery recovery access denied.');
            }

            $recorded = $this->repository->findRecordedAttempt($snapshot, $observation);
            if ($recorded !== null) {
                return $recorded;
            }

            if ($snapshot->operation->state !== DeliveryOperationState::Leased) {
                throw new RuntimeException('Delivery operation is not leased for a new recovery outcome.');
            }

            $retryDecision = $this->retryPolicy->decide($observation);
            $breakerFailures = $this->breakerFailuresAfterOutcome(
                current: $snapshot->breakerConsecutiveFailures,
                outcomeClass: $retryDecision->outcomeClass,
            );
            $breakerDecision = $this->breakerPolicy->afterOutcome(
                state: $snapshot->breakerState,
                outcomeClass: $retryDecision->outcomeClass,
                consecutiveFailuresAfterOutcome: $breakerFailures,
                now: $observedAt,
                providerResetAt: $observation->resetAt,
                failureThreshold: $this->positiveConfigInt('delivery.recovery.breaker_failure_threshold', 3),
                openSeconds: $this->positiveConfigInt('delivery.recovery.breaker_open_seconds', 60),
            );
            $deadLetterDecision = $this->deadLetterPolicy->decide(
                retryDecision: $retryDecision,
                operationExpired: $operationExpired,
                invariantCorruption: $invariantCorruption,
            );
            $nextAttemptAt = $this->nextAttemptAt($retryDecision, $observation, $observedAt);

            $result = $this->repository->recordAttemptOutcome(
                attemptId: $attemptId,
                snapshot: $snapshot,
                observation: $observation,
                retryDecision: $retryDecision,
                breakerDecision: $breakerDecision,
                breakerConsecutiveFailuresAfterOutcome: $breakerFailures,
                deadLetterDecision: $deadLetterDecision,
                observedAt: $observedAt,
                nextAttemptAt: $nextAttemptAt,
                maxReconciliationProbeAttempts: $this->positiveConfigInt(
                    'delivery.recovery.reconciliation_max_probes',
                    3,
                ),
            );

            if ($result->changed) {
                $this->audit->record(
                    workspaceId: $context->workspaceId,
                    brandId: $context->brandId,
                    actorId: $context->actorId,
                    action: self::AUDIT_ACTION,
                    subjectType: 'delivery_operation',
                    subjectId: $result->operation->id,
                    evidence: [
                        'attempt_id' => $result->attemptId,
                        'attempt_number' => $result->attemptNumber,
                        'outcome_class' => $result->outcomeClass->value,
                        'recovery_action' => $result->action->value,
                        'operation_state' => $result->operation->state->value,
                        'next_attempt_at' => $result->nextAttemptAt?->format(DATE_ATOM),
                        'dead_letter_reason' => $result->deadLetterReason?->value,
                        'reconciliation_resolution' => $result->reconciliationResolution?->value,
                        'breaker_state' => $breakerDecision->state->value,
                        'breaker_reason' => $breakerDecision->reason,
                        'breaker_failure_streak' => $breakerFailures,
                    ],
                );
            }

            return $result;
        });

        if (
            $result->operation->state !== DeliveryOperationState::Leased
            && (bool) config('delivery.admission.concurrency_enabled', true)
        ) {
            $this->coordinator->release($result->operation->workspaceId, $result->operation->id);
        }

        return $result;
    }

    private function breakerFailuresAfterOutcome(
        int $current,
        DeliveryAttemptOutcomeClass $outcomeClass,
    ): int {
        return match ($outcomeClass) {
            DeliveryAttemptOutcomeClass::ProviderAccepted => 0,
            DeliveryAttemptOutcomeClass::PermanentValidation => $current,
            default => $current + 1,
        };
    }

    private function nextAttemptAt(
        DeliveryRetryDecision $decision,
        DeliveryFailureObservation $observation,
        DateTimeImmutable $observedAt,
    ): ?DateTimeImmutable {
        if (! $decision->retryAllowed) {
            return null;
        }

        $baseSeconds = $this->positiveConfigInt('delivery.recovery.retry_base_delay_seconds', 5);
        $maxSeconds = max(
            $baseSeconds,
            $this->positiveConfigInt('delivery.recovery.retry_max_delay_seconds', 300),
        );
        $exponent = min(20, max(0, $observation->attemptNumber - 1));
        $localDelay = min($maxSeconds, $baseSeconds * (2 ** $exponent));
        $delaySeconds = max($localDelay, $decision->minimumDelaySeconds ?? 0);
        $candidate = $observedAt->add(new DateInterval('PT'.$delaySeconds.'S'));

        if ($decision->resetAt !== null && $decision->resetAt > $candidate) {
            return $decision->resetAt;
        }

        return $candidate;
    }

    private function positiveConfigInt(string $key, int $default): int
    {
        $configured = config($key, $default);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : $default;
    }
}
