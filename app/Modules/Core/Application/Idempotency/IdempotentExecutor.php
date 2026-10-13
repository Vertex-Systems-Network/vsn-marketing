<?php

namespace App\Modules\Core\Application\Idempotency;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\IdempotencyRepository;
use App\Modules\Core\Domain\Idempotency\IdempotencyClaim;
use App\Modules\Core\Domain\Idempotency\IdempotencyHoldRequired;
use RuntimeException;
use Throwable;

final readonly class IdempotentExecutor
{
    public const string AUDIT_COMPLETED = 'idempotency.execution.completed';

    public const string AUDIT_FAILED = 'idempotency.execution.failed';

    public const string AUDIT_HELD = 'idempotency.execution.held';

    public function __construct(
        private IdempotencyRepository $idempotency,
        private AuditRecorder $audit,
    ) {}

    public function run(
        string $workspaceId,
        string $scope,
        string $key,
        callable $operation,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): array {
        $claim = $this->idempotency->claim($workspaceId, $scope, $key);

        if ($claim->state === IdempotencyClaim::COMPLETED) {
            return $claim->result ?? [];
        }

        if ($claim->state === IdempotencyClaim::IN_PROGRESS) {
            throw new RuntimeException('The idempotency key is already in progress.');
        }

        if ($claim->state === IdempotencyClaim::HELD) {
            throw new IdempotencyHoldRequired('The idempotency key is held for operator recovery.');
        }

        try {
            $result = $operation();
            if (! is_array($result)) {
                throw new RuntimeException('Idempotent operations must return an array result.');
            }

            $this->idempotency->complete($workspaceId, $scope, $key, $result);
        } catch (Throwable $exception) {
            $held = $exception instanceof IdempotencyHoldRequired;
            if ($held) {
                $this->idempotency->hold($workspaceId, $scope, $key, $exception->getMessage());
            } else {
                $this->idempotency->fail($workspaceId, $scope, $key, $exception->getMessage());
            }
            $this->audit->record(
                workspaceId: $workspaceId,
                action: $held ? self::AUDIT_HELD : self::AUDIT_FAILED,
                evidence: ['scope' => $scope, 'idempotency_key' => $key, 'error' => mb_substr($exception->getMessage(), 0, 500)],
                actorId: $actorId,
                subjectType: 'idempotency',
                subjectId: "{$scope}:{$key}",
                correlationId: $correlationId,
            );

            throw $exception;
        }

        $this->audit->record(
            workspaceId: $workspaceId,
            action: self::AUDIT_COMPLETED,
            evidence: ['scope' => $scope, 'idempotency_key' => $key],
            actorId: $actorId,
            subjectType: 'idempotency',
            subjectId: "{$scope}:{$key}",
            correlationId: $correlationId,
        );

        return $result;
    }

    public function hold(string $workspaceId, string $scope, string $key, string $reason): void
    {
        $this->idempotency->hold($workspaceId, $scope, $key, $reason);
    }

    /**
     * Serialize equivalent writes across distinct operation keys. A crashed
     * guard stays processing and fails closed until operator reconciliation.
     */
    public function runGuarded(
        string $workspaceId,
        string $scope,
        string $key,
        string $guardScope,
        string $guardKey,
        callable $operation,
        ?string $actorId = null,
    ): array {
        $guard = $this->idempotency->claim($workspaceId, $guardScope, $guardKey);
        if ($guard->state === IdempotencyClaim::HELD) {
            throw new IdempotencyHoldRequired('AI tool is held for operator recovery.');
        }
        if ($guard->state !== IdempotencyClaim::ACQUIRED) {
            throw new RuntimeException('Equivalent AI tool operation is already in progress.');
        }

        try {
            $result = $this->run($workspaceId, $scope, $key, $operation, $actorId);
        } catch (IdempotencyHoldRequired $exception) {
            $this->idempotency->hold($workspaceId, $guardScope, $guardKey, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->idempotency->fail($workspaceId, $guardScope, $guardKey, 'Guard released after safe failure.');

            throw $exception;
        }

        $this->idempotency->fail($workspaceId, $guardScope, $guardKey, 'Guard released after completion.');

        return $result;
    }

    public function isHeld(string $workspaceId, string $scope, string $key): bool
    {
        return $this->idempotency->isHeld($workspaceId, $scope, $key);
    }
}
