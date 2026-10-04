<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiCreativeDraftPolicy;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiCreativePolicyAuthority;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final class AiCreativeReviewGate
{
    public function __construct(private readonly AiCreativePolicyAuthority $authority, private readonly AiContextPermission $permissions) {}

    public function review(TenantContext $scope, array $draft): array
    {
        $hash = $draft['candidate_sha256'] ?? null;
        if (! is_string($hash) || $hash !== AiCreativeDraftPolicy::hash($draft)
            || ($draft['status'] ?? null) !== 'draft' || ($draft['review_status'] ?? null) !== 'pending_independent_review'
            || ($draft['workspace_id'] ?? null) !== $scope->workspaceId || ($draft['brand_id'] ?? null) !== $scope->brandId
            || ($draft['created_by_actor_id'] ?? null) === $scope->actorId
            || ! $this->permissions->allows($scope, PermissionCatalog::AI_APPROVE)) {
            throw new InvalidArgumentException('Creative review scope/permission/fingerprint rejected.');
        }
        $reviews = $this->authority->reviewers($scope, $hash);
        $kinds = array_keys($reviews);
        sort($kinds);
        if ($kinds !== ['brand', 'rights', 'safety']) {
            throw new InvalidArgumentException('Independent creative review incomplete.');
        }
        foreach ($reviews as $reviewer) {
            if (! is_string($reviewer) || $reviewer === '' || in_array($reviewer, [$draft['created_by_actor_id'], 'content', 'brand_guardian', 'compliance_guard'], true)) {
                throw new InvalidArgumentException('Creative self-review rejected.');
            }
        }

        return ['status' => 'reviewed_draft', 'candidate_sha256' => $hash, 'reviews' => $reviews,
            'workspace_id' => $scope->workspaceId, 'brand_id' => $scope->brandId,
            'publication_authorized' => false, 'evidence_kind' => 'offline_contract'];
    }
}
