<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\SegmentValidator;
use App\Modules\Segmentation\Domain\SegmentProposalGuard;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use RuntimeException;

final readonly class SaveSegmentVersion
{
    public function __construct(
        private DatabaseManager $database,
        private SegmentValidator $validator,
        private SegmentProposalGuard $guard,
    ) {}

    /** @param array<string, mixed> $definition @return array{id: string, version: int, hash: string} */
    public function create(string $name, array $definition, TenantContext $scope): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new RuntimeException('invalid_segment_name');
        }
        $ast = $this->validator->normalize($definition);
        $this->guard->assertSafeDefinition($ast);
        $hash = $this->validator->hash($ast);
        $id = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->database->transaction(function () use ($scope, $name, $ast, $hash, $id, $versionId, $now): void {
            $this->database->table('segment_definitions')->insert([
                'id' => $id,
                'workspace_id' => $scope->workspaceId,
                'name' => $name,
                'status' => 'draft',
                'created_by_actor_id' => $scope->actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->database->table('segment_definition_versions')->insert([
                'id' => $versionId,
                'workspace_id' => $scope->workspaceId,
                'definition_id' => $id,
                'version_number' => 1,
                'definition_ast' => json_encode($ast, JSON_THROW_ON_ERROR),
                'definition_hash' => $hash,
                'created_by_actor_id' => $scope->actorId,
                'created_at' => $now,
            ]);
        });

        return ['id' => $id, 'version' => 1, 'hash' => $hash];
    }

    /** Append only; a published pointer never floats to this new draft. @param array<string, mixed> $definition @return array{id: string, version: int, hash: string} */
    public function revise(string $id, array $definition, TenantContext $scope): array
    {
        $ast = $this->validator->normalize($definition);
        $this->guard->assertSafeDefinition($ast);
        $hash = $this->validator->hash($ast);

        return $this->database->transaction(function () use ($id, $ast, $hash, $scope): array {
            $segment = $this->database->table('segment_definitions')
                ->where('id', $id)->where('workspace_id', $scope->workspaceId)->lockForUpdate()->first();
            if ($segment === null) {
                throw new SegmentDefinitionException('segment_not_found', '$.segment_id');
            }
            $version = 1 + (int) $this->database->table('segment_definition_versions')
                ->where('definition_id', $id)->where('workspace_id', $scope->workspaceId)->max('version_number');
            $this->database->table('segment_definition_versions')->insert([
                'id' => (string) Str::uuid(), 'workspace_id' => $scope->workspaceId,
                'definition_id' => $id, 'version_number' => $version,
                'definition_ast' => json_encode($ast, JSON_THROW_ON_ERROR),
                'definition_hash' => $hash, 'created_by_actor_id' => $scope->actorId,
                'created_at' => new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ]);
            $this->database->table('segment_definitions')->where('id', $id)
                ->where('workspace_id', $scope->workspaceId)->update(['updated_at' => now()]);

            return ['id' => $id, 'version' => $version, 'hash' => $hash];
        });
    }

    public function publish(string $id, int $version, TenantContext $scope): void
    {
        $this->database->transaction(function () use ($id, $version, $scope): void {
            $segment = $this->database->table('segment_definitions')
                ->where('id', $id)->where('workspace_id', $scope->workspaceId)->lockForUpdate()->first();
            if ($segment === null || $version < 1 || ! $this->database->table('segment_definition_versions')
                ->where('definition_id', $id)->where('workspace_id', $scope->workspaceId)
                ->where('version_number', $version)->exists()) {
                throw new SegmentDefinitionException('segment_version_not_found', '$.version');
            }
            $this->database->table('segment_definitions')->where('id', $id)
                ->where('workspace_id', $scope->workspaceId)->update([
                    'published_version_number' => $version, 'status' => 'published', 'updated_at' => now(),
                ]);
        });
    }
}
