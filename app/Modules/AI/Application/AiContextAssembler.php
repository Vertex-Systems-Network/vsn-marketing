<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiContextSanitizer;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiContextRepository;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Context data is quarantined; the manifest never grants model/tool authority. */
final class AiContextAssembler
{
    public function __construct(
        private readonly AiContextRepository $repository,
        private readonly AiContextPermission $permissions,
        private readonly AiContextSanitizer $sanitizer,
    ) {}

    /**
     * @param  list<string>  $sourceIds  Server-selected source identifiers, not model output.
     * @return array{manifest: array<string, mixed>, manifest_sha256: string, untrusted_context: list<array<string, string>>}
     */
    public function assemble(TenantContext $scope, ?string $customerId, ?string $runId, array $sourceIds, DateTimeImmutable $at): array
    {
        if (! $this->permissions->allows($scope, PermissionCatalog::AI_EXECUTE)
            || count($sourceIds) > 16 || count($sourceIds) !== count(array_unique($sourceIds))) {
            throw new InvalidArgumentException('AI context scope or source selection denied.');
        }
        foreach ($sourceIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidArgumentException('Invalid AI context source identifier.');
            }
        }

        $rows = $this->repository->fetch($scope, $customerId, $runId, $sourceIds, $at);
        if (count($rows) !== count($sourceIds)) {
            throw new InvalidArgumentException('AI context source missing, expired or outside the authorized scope.');
        }
        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (! is_string($id) || ! in_array($id, $sourceIds, true) || isset($byId[$id])) {
                throw new InvalidArgumentException('AI context repository returned an invalid source.');
            }
            $byId[$id] = $row;
        }
        sort($sourceIds, SORT_STRING);

        $manifestSources = [];
        $untrusted = [];
        $totalBytes = 0;
        foreach ($sourceIds as $id) {
            $row = $byId[$id];
            $permission = $row['permission'] ?? null;
            $content = $row['content'] ?? null;
            if (($row['workspace_id'] ?? null) !== $scope->workspaceId
                || ($row['brand_id'] ?? null) !== $scope->brandId
                || ($row['customer_id'] ?? null) !== $customerId
                || ($row['run_id'] ?? null) !== $runId
                || ! in_array($row['classification'] ?? null, ['public', 'approved_non_personal'], true)
                || ! is_string($permission) || ! PermissionCatalog::contains($permission)
                || ! $this->permissions->allows($scope, $permission)
                || ! is_string($content) || ! $this->sanitizer->safe($content)
                || ! is_string($row['revision'] ?? null) || $row['revision'] === ''
                || strlen($row['revision']) > 128 || ! $this->sanitizer->safe($row['revision'])
                || ! is_string($row['provenance_reference'] ?? null) || $row['provenance_reference'] === ''
                || strlen($row['provenance_reference']) > 255 || ! $this->sanitizer->safe($row['provenance_reference'])
                || ! in_array($row['source_kind'] ?? null, ['approved_fact', 'brand_guideline', 'run_note'], true)
                || ! is_string($row['expires_at'] ?? null)
                || strtotime($row['expires_at']) === false
                || strtotime($row['expires_at']) <= $at->getTimestamp()
                || ($row['deleted_at'] ?? null) !== null) {
                throw new InvalidArgumentException('AI context record failed permission, provenance or data policy.');
            }
            $totalBytes += strlen($content);
            if ($totalBytes > 32768) {
                throw new InvalidArgumentException('AI context byte budget exceeded.');
            }
            $manifestSources[] = [
                'source_id' => $id, 'source_kind' => $row['source_kind'],
                'revision' => $row['revision'], 'permission' => $permission,
                'provenance_reference' => $row['provenance_reference'],
                'expires_at' => $row['expires_at'], 'content_sha256' => hash('sha256', $content),
            ];
            $untrusted[] = ['source_id' => $id, 'text' => $content, 'trust' => 'untrusted_data'];
        }

        $manifest = [
            'workspace_id' => $scope->workspaceId, 'brand_id' => $scope->brandId,
            'customer_id' => $customerId, 'run_id' => $runId,
            'assembled_at' => $at->format('Y-m-d\TH:i:s\Z'), 'sources' => $manifestSources,
        ];

        return ['manifest' => $manifest,
            'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
            'untrusted_context' => $untrusted];
    }
}
