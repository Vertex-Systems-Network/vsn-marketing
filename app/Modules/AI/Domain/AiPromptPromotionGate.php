<?php

namespace App\Modules\AI\Domain;

use App\Modules\AI\Domain\Contracts\AiPromptPromotionAuthority;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

/** Authorizes an offline candidate change/rollback; never activates a provider route. */
final class AiPromptPromotionGate
{
    public function __construct(private readonly AiPromptPromotionAuthority $authority) {}

    public function authorize(array $definition, AiEvaluationReport $report, TenantContext $scope, string $proposerActor, string $releaseKind): array
    {
        if ($releaseKind !== 'offline' || $proposerActor === '' || $proposerActor === $scope->actorId
            || ! $report->passed || $report->promptHash !== $definition['prompt_sha256']
            || $report->datasetHash !== $definition['dataset_sha256'] || $report->suiteId !== $definition['eval_id']) {
            throw new InvalidArgumentException('Prompt promotion evidence/mode/self-approval rejected.');
        }
        $candidateHash = hash('sha256', json_encode([
            $definition['agent_id'], $definition['prompt_id'], $definition['version'],
            $definition['prompt_sha256'], $definition['dataset_sha256'], $scope->toArray(), $proposerActor, $releaseKind,
        ], JSON_THROW_ON_ERROR));
        $reviewer = $this->authority->reviewer($scope, $candidateHash, $report->hash());
        if ($reviewer === null || $reviewer === '' || in_array($reviewer, [$proposerActor, $scope->actorId, $definition['agent_id']], true)) {
            throw new InvalidArgumentException('Independent prompt review missing or invalid.');
        }

        return ['status' => 'authorized_offline', 'prompt_id' => $definition['prompt_id'],
            'prompt_version' => $definition['version'], 'candidate_sha256' => $candidateHash,
            'report_sha256' => $report->hash(), 'reviewer_actor_id' => $reviewer,
            'workspace_id' => $scope->workspaceId, 'brand_id' => $scope->brandId];
    }
}
