<?php

namespace App\Modules\AI\Application;

use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Durable replay-safe offline proposal receipt. Does not dispatch tools,
 * messages, providers, workers, ad campaigns or billing operations.
 */
final readonly class BoundedAutonomyOfflineReceipt
{
    public function __construct(
        private BoundedAutonomyPreview $previews,
        private IdempotentExecutor $idempotency,
    ) {}

    public function record(
        TenantContext $scope,
        string $runId,
        array $goal,
        array $actions,
        DateTimeImmutable $observedAt,
    ): array {
        // Revalidate every caller-supplied field against a server-controlled
        // policy on each attempt, including replays and conflicting proposals.
        $preview = $this->previews->preview($scope, $runId, $goal, $actions, $observedAt);
        if ($preview['execution_authorized'] !== false || $preview['stages']['execute'] !== 'disabled') {
            throw new InvalidArgumentException('Offline autonomy execution boundary rejected.');
        }

        $fingerprint = $preview['snapshot_sha256'];
        $receipt = $this->idempotency->run(
            $scope->workspaceId,
            'ai-offline-autonomy-preview:v1',
            $runId,
            static fn (): array => [
                'status' => 'recorded_offline',
                'run_id' => $runId,
                'tenant' => $scope->toArray(),
                'snapshot_sha256' => $fingerprint,
                'execution_authorized' => false,
                'stages' => $preview['stages'],
            ],
            $scope->actorId,
        );

        // A previous completed receipt may belong to a different goal, actor,
        // plan, source, policy revision or expiry, even with the same run ID.
        if (($receipt['status'] ?? null) !== 'recorded_offline'
            || ($receipt['run_id'] ?? null) !== $runId
            || ($receipt['tenant'] ?? null) !== $scope->toArray()
            || ($receipt['snapshot_sha256'] ?? null) !== $fingerprint
            || ($receipt['execution_authorized'] ?? null) !== false
            || ($receipt['stages'] ?? null) !== $preview['stages']) {
            throw new InvalidArgumentException('Conflicting autonomy replay or altered offline receipt rejected.');
        }

        return $receipt;
    }
}
