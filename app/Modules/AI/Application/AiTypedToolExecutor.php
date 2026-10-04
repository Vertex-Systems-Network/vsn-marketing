<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiSchemaValidator;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiReversibleToolHandler;
use App\Modules\AI\Domain\Contracts\AiToolApproval;
use App\Modules\AI\Domain\Contracts\AiToolHandler;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Definitions and canonical registry come from server configuration, never model output. */
final class AiTypedToolExecutor
{
    public function __construct(
        private readonly AiContextPermission $permissions,
        private readonly AiToolApproval $approvals,
        private readonly IdempotentExecutor $idempotency,
        private readonly AuditRecorder $audit,
        private readonly array $registry,
        private readonly array $definitions,
        private readonly AiSchemaValidator $schemas = new AiSchemaValidator,
    ) {}

    public function execute(TenantContext $scope, string $toolId, array $arguments, string $risk, string $key, ?string $approvalReference = null): array
    {
        $registered = null;
        foreach ($this->registry as $candidate) {
            if (($candidate['id'] ?? null) === $toolId) {
                $registered = $candidate;
                break;
            }
        }
        $definition = $this->definitions[$toolId] ?? null;
        $tiers = ['R0' => 0, 'R1' => 1, 'R2' => 2, 'R3' => 3];
        $required = $definition['risk'] ?? null;
        $permission = $definition['permission'] ?? null;
        if ($registered === null || ! is_array($definition) || ! isset($tiers[$risk], $tiers[$required])
            || ! isset($tiers[$registered['min_risk'] ?? '']) || $tiers[$required] < $tiers[$registered['min_risk']]
            || $tiers[$risk] < $tiers[$required] || ($definition['effect'] ?? null) !== $registered['effect']
            || ! in_array($registered['effect'], ['read', 'proposal', 'reversible_write'], true)
            || ! is_string($permission) || ! PermissionCatalog::contains($permission)
            || ! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)
            || ! $this->permissions->allows($scope, $permission)
            || ! ($definition['handler'] ?? null) instanceof AiToolHandler
            || ($registered['effect'] === 'reversible_write' && ! $definition['handler'] instanceof AiReversibleToolHandler)
            || ! is_string($definition['version'] ?? null) || $definition['version'] === ''
            || strlen($key) > 128 || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $key)) {
            throw new InvalidArgumentException('AI tool authority rejected.');
        }
        $this->schemas->validate($arguments, $definition['arguments_schema']);
        $fingerprint = hash('sha256', json_encode([$scope->workspaceId, $scope->brandId, $scope->actorId,
            $toolId, $definition['version'], $arguments], JSON_THROW_ON_ERROR));
        // Every write and high-risk call requires independently verified, argument-bound approval.
        if (($registered['effect'] === 'reversible_write' || $risk === 'R3')
            && ! $this->approvals->approved($scope, $toolId, $fingerprint, $approvalReference)) {
            throw new InvalidArgumentException('AI tool independent approval required.');
        }
        $this->audit->record($scope->workspaceId, 'ai.tool.authorized',
            ['tool_id' => $toolId, 'version' => $definition['version'], 'arguments_sha256' => $fingerprint, 'risk' => $risk],
            brandId: $scope->brandId, actorId: $scope->actorId);
        $result = $this->idempotency->run($scope->workspaceId, 'ai-tool:'.$toolId.':'.$definition['version'], $key,
            function () use ($scope, $arguments, $definition, $fingerprint): array {
                $handler = $definition['handler'];
                if ($handler instanceof AiReversibleToolHandler && ! $handler->preflight($scope, $arguments)) {
                    throw new RuntimeException('AI tool preflight denied.');
                }
                try {
                    $output = $handler->execute($scope, $arguments);
                    $this->schemas->validate($output, $definition['result_schema']);
                    if ($handler instanceof AiReversibleToolHandler && ! $handler->postcondition($scope, $arguments, $output)) {
                        throw new RuntimeException('AI tool postcondition failed.');
                    }
                } catch (Throwable) {
                    if ($handler instanceof AiReversibleToolHandler) {
                        try {
                            $handler->rollback($scope, $arguments);
                        } catch (Throwable) {
                            throw new RuntimeException('AI tool rollback requires operator recovery.');
                        }
                    }
                    throw new RuntimeException('AI tool execution or validation failed.');
                }

                return ['status' => 'executed', 'arguments_sha256' => $fingerprint, 'output' => $output];
            }, $scope->actorId);
        if (($result['arguments_sha256'] ?? null) !== $fingerprint) {
            throw new InvalidArgumentException('AI tool replay arguments or actor changed.');
        }

        return $result;
    }
}
