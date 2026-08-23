<?php

namespace App\Modules\Core\Application\Idempotency;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\IdempotencyRepository;
use App\Modules\Core\Domain\Idempotency\IdempotencyClaim;
use RuntimeException;
use Throwable;

final readonly class IdempotentExecutor
{
    public const string AUDIT_COMPLETED = 'idempotency.execution.completed';

    public const string AUDIT_FAILED = 'idempotency.execution.failed';

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

        try {
            $result = $operation();
            if (! is_array($result)) {
                throw new RuntimeException('Idempotent operations must return an array result.');
            }

            $this->idempotency->complete($workspaceId, $scope, $key, $result);
        } catch (Throwable $exception) {
            $this->idempotency->fail($workspaceId, $scope, $key, $exception->getMessage());
            $this->audit->record(
                workspaceId: $workspaceId,
                action: self::AUDIT_FAILED,
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
}
