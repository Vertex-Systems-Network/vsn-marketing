<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\SegmentValidator;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class SaveSegmentVersion
{
    public function __construct(
        private DatabaseManager $database,
        private SegmentValidator $validator,
    ) {}

    /** @param array<string, mixed> $definition @return array{id: string, version: int, hash: string} */
    public function create(string $name, array $definition, TenantContext $scope): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new RuntimeException('invalid_segment_name');
        }
        $ast = $this->validator->normalize($definition);
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
}
