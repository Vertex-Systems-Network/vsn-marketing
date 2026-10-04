<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentProposalGuard;
use App\Modules\Segmentation\Domain\SegmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class PublishSegmentVersion
{
    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
        private SegmentValidator $validator,
        private SegmentProposalGuard $guard,
        private DeterministicSegmentCompiler $compiler,
        private SaveSegmentVersion $versions,
        private AuditRecorder $audit,
    ) {}

    /** @return array{id: string, version: int, hash: string} */
    public function publish(string $id, int $version, TenantContext $scope, User $actor, bool $confirmed): array
    {
        if (! $confirmed || (string) $actor->getKey() !== $scope->actorId
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::CONTACT_READ)
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::CONTACT_WRITE)) {
            throw new AuthorizationException('Publishing requires confirmation and workspace edit permission.');
        }
        $record = $this->database->table('segment_definition_versions')
            ->where('workspace_id', $scope->workspaceId)->where('definition_id', $id)
            ->where('version_number', $version)->first();
        if ($record === null) {
            throw new SegmentDefinitionException('segment_version_not_found', '$.version');
        }
        $definition = is_string($record->definition_ast)
            ? json_decode($record->definition_ast, true) : $record->definition_ast;
        if (! is_array($definition) || $this->validator->hash($definition) !== $record->definition_hash) {
            throw new SegmentDefinitionException('stored_definition_invalid', '$.version');
        }
        $this->guard->assertSafeDefinition($definition);
        $this->compiler->compile($definition, $scope, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->versions->publish($id, $version, $scope);
        $this->audit->record(
            workspaceId: $scope->workspaceId, action: 'segment.version.published',
            evidence: ['definition_hash' => $record->definition_hash, 'definition_version' => $version],
            brandId: $scope->brandId, actorId: $scope->actorId,
            subjectType: 'segment_definition', subjectId: $id,
        );

        return ['id' => $id, 'version' => $version, 'hash' => $record->definition_hash];
    }
}
