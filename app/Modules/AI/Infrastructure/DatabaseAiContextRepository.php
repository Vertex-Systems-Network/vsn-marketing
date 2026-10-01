<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\AiContextSanitizer;
use App\Modules\AI\Domain\AiContextSourcePolicy;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiContextRepository;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DatabaseAiContextRepository implements AiContextRepository
{
    public function __construct(
        private readonly AiContextPermission $permissions,
        private readonly AiContextSanitizer $sanitizer,
        private readonly AiContextSourcePolicy $sourcePolicy = new AiContextSourcePolicy,
    ) {}

    public function put(TenantContext $scope, ?string $customerId, ?string $runId, array $source, DateTimeImmutable $at): string
    {
        $permission = $source['permission'] ?? null;
        $content = $source['content'] ?? null;
        $provenance = $source['provenance_reference'] ?? null;
        $revision = $source['revision'] ?? null;
        $expiry = $source['expires_at'] ?? null;
        $contract = $this->sourcePolicy->contract($source['source_kind'] ?? null);
        if (! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)
            || ! is_string($permission) || ! PermissionCatalog::contains($permission)
            || ! $this->permissions->allows($scope, $permission)
            || ! is_string($content) || ! $this->sanitizer->safe($content)
            || ! is_string($provenance) || strlen($provenance) > 255 || ! $this->sanitizer->safe($provenance)
            || ! is_string($revision) || strlen($revision) > 128 || ! $this->sanitizer->safe($revision)
            || $contract === null || $permission !== $contract['permission']
            || ($source['classification'] ?? null) !== $contract['classification']
            || ! $expiry instanceof DateTimeImmutable || $expiry <= $at || $expiry > $at->modify('+30 days')) {
            throw new InvalidArgumentException('AI context write denied by scope, permission, expiry or data policy.');
        }

        $id = (string) Str::uuid();
        DB::table('ai_context_memories')->insert([
            'id' => $id, 'workspace_id' => $scope->workspaceId, 'brand_id' => $scope->brandId,
            'customer_id' => $customerId, 'run_id' => $runId,
            'source_kind' => $source['source_kind'], 'classification' => $source['classification'],
            'permission' => $permission, 'content' => $content,
            'provenance_reference' => $provenance, 'revision' => $revision,
            'expires_at' => $expiry->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function fetch(TenantContext $scope, ?string $customerId, ?string $runId, array $sourceIds, DateTimeImmutable $at): array
    {
        if ($sourceIds === [] || ! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)) {
            return [];
        }

        $rows = DB::table('ai_context_memories')
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
        foreach ($rows as $row) {
            $contract = $this->sourcePolicy->contract($row['source_kind'] ?? null);
            if ($contract === null || ($row['permission'] ?? null) !== $contract['permission']
                || ($row['classification'] ?? null) !== $contract['classification']
                || ! $this->permissions->allows($scope, $contract['permission'])) {
                return [];
            }
        }

        return $rows;
    }

    public function delete(TenantContext $scope, ?string $customerId, ?string $runId, string $sourceId): bool
    {
        if (! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)) {
            return false;
        }

        return DB::transaction(function () use ($scope, $customerId, $runId, $sourceId): bool {
            $query = DB::table('ai_context_memories')->where('workspace_id', $scope->workspaceId)
                ->where('brand_id', $scope->brandId)->where('customer_id', $customerId)
                ->where('run_id', $runId)->where('id', $sourceId)->whereNull('deleted_at');
            $row = (clone $query)->lockForUpdate()->first();
            $contract = $row === null ? null : $this->sourcePolicy->contract($row->source_kind);
            if ($row === null || $contract === null || $row->permission !== $contract['permission']
                || $row->classification !== $contract['classification']
                || ! $this->permissions->allows($scope, $row->permission)) {
                return false;
            }

            return $query->update(['content' => '', 'deleted_at' => now(), 'updated_at' => now()]) === 1;
        }, 3);
    }
}
