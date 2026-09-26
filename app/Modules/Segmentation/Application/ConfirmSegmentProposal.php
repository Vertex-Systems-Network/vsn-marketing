<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ConfirmSegmentProposal
{
    public const AUDIT_ACTION = 'segment.proposal.accepted';

    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private SegmentFieldRegistry $fields,
        private SegmentValidator $validator,
        private DeterministicSegmentCompiler $compiler,
        private SaveSegmentVersion $saveVersion,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $definition @return array{id: string, version: int, hash: string} */
    public function store(
        string $name,
        array $definition,
        TenantContext $scope,
        User $actor,
        bool $confirmed,
    ): array {
        if (! $confirmed) {
            throw new AuthorizationException('Explicit segment confirmation is required.');
        }

        if ((string) $actor->getKey() !== $scope->actorId
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::CONTACT_READ)
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::CONTACT_WRITE)) {
            throw new AuthorizationException('Workspace permission denied.');
        }

        $normalized = $this->validator->normalize($definition);
        $allowedFields = $this->fields->availableTo([PermissionCatalog::CONTACT_READ]);
        $this->assertAuthorizedFields($normalized['root'], array_keys($allowedFields), '$.root');

        // Rebuild the safe plan at the moment of confirmation to reject stale event/list/tag references.
        $compiled = $this->compiler->compile(
            $normalized,
            $scope,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $saved = $this->saveVersion->create($name, $normalized, $scope);

        $this->audit->record(
            workspaceId: $scope->workspaceId,
            brandId: $scope->brandId,
            actorId: $scope->actorId,
            action: self::AUDIT_ACTION,
            evidence: [
                'schema_version' => 1,
                'origin' => 'operator_confirmed_structured_proposal',
                'definition_hash' => $compiled->definitionHash,
                'segment_definition_id' => $saved['id'],
                'definition_version' => $saved['version'],
            ],
            subjectType: 'segment_definition',
            subjectId: $saved['id'],
        );

        return $saved;
    }

    /** @param array<string, mixed> $node @param list<string> $allowedFields */
    private function assertAuthorizedFields(array $node, array $allowedFields, string $path): void
    {
        if ($node['type'] === 'group') {
            foreach ($node['children'] as $index => $child) {
                $this->assertAuthorizedFields($child, $allowedFields, $path.'.children.'.$index);
            }

            return;
        }

        if ($node['type'] === 'not') {
            $this->assertAuthorizedFields($node['child'], $allowedFields, $path.'.child');

            return;
        }

        if ($node['type'] === 'attribute' && ! in_array($node['field'], $allowedFields, true)) {
            throw new SegmentDefinitionException('field_not_authorized', $path.'.field');
        }
    }
}
