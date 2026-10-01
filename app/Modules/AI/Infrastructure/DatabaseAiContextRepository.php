<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiContextRepository;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseAiContextRepository implements AiContextRepository
{
    public function __construct(private readonly AiContextPermission $permissions) {}

    public function fetch(TenantContext $scope, ?string $customerId, ?string $runId, array $sourceIds, DateTimeImmutable $at): array
    {
        if ($sourceIds === [] || ! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)) {
            return [];
        }

        return DB::table('ai_context_memories')
            ->where('workspace_id', $scope->workspaceId)
            ->where('brand_id', $scope->brandId)
            ->where('customer_id', $customerId)
            ->where('run_id', $runId)
            ->whereIn('id', $sourceIds)
            ->whereNull('deleted_at')
            ->where('expires_at', '>', $at->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->limit(16)
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    public function delete(TenantContext $scope, ?string $customerId, ?string $runId, string $sourceId): bool
    {
        if (! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)) {
            return false;
        }

        return DB::table('ai_context_memories')->where('workspace_id', $scope->workspaceId)
            ->where('brand_id', $scope->brandId)->where('customer_id', $customerId)
            ->where('run_id', $runId)->where('id', $sourceId)->whereNull('deleted_at')
            ->update(['content' => '', 'deleted_at' => now(), 'updated_at' => now()]) === 1;
    }
}
