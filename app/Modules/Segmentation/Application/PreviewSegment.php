<?php

namespace App\Modules\Segmentation\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Domain\CompiledSegment;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentProposalGuard;
use App\Modules\Segmentation\Domain\SegmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Throwable;

/** Count-only preview. Membership identities and PII never leave this boundary. */
final readonly class PreviewSegment
{
    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private SegmentFieldRegistry $fields,
        private SegmentValidator $validator,
        private SegmentProposalGuard $guard,
        private DeterministicSegmentCompiler $compiler,
        private DatabaseManager $database,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function evaluate(array $definition, TenantContext $scope, User $actor, ?string $segmentId = null, ?int $version = null): array
    {
        if ((string) $actor->getKey() !== $scope->actorId
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::CONTACT_READ)) {
            throw new AuthorizationException('Workspace permission denied.');
        }
        if ($segmentId !== null || $version !== null) {
            if ($segmentId === null || $version === null || $version < 1) {
                throw new SegmentDefinitionException('segment_version_required', '$.version');
            }
            $record = $this->database->table('segment_definition_versions as v')
                ->join('segment_definitions as d', function ($join): void {
                    $join->on('d.id', '=', 'v.definition_id')->on('d.workspace_id', '=', 'v.workspace_id');
                })
                ->where('v.workspace_id', $scope->workspaceId)
                ->where('v.definition_id', $segmentId)
                ->where('v.version_number', $version)
                ->first(['v.definition_ast', 'v.definition_hash']);
            if ($record === null) {
                throw new SegmentDefinitionException('segment_version_not_found', '$.version');
            }
            $stored = is_string($record->definition_ast)
                ? json_decode($record->definition_ast, true)
                : $record->definition_ast;
            if (! is_array($stored) || $this->validator->hash($stored) !== $record->definition_hash) {
                throw new SegmentDefinitionException('stored_definition_invalid', '$.version');
            }
            if ($definition !== [] && $this->validator->hash($definition) !== $record->definition_hash) {
                throw new SegmentDefinitionException('definition_version_mismatch', '$.definition');
            }
            $definition = $stored;
        }
        $normalized = $this->validator->normalize($definition);
        $this->guard->assertSafeDefinition($normalized);
        $this->assertFields($normalized['root'], array_keys($this->fields->availableTo([PermissionCatalog::CONTACT_READ])));
        $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $compiled = $this->compiler->compile($normalized, $scope, $instant);
        // This is a product limit for bounded work, not a production capacity SLO.
        $limit = max(1, min(1000, (int) config('segmentation.max_count_probe', 250)));
        $connection = $this->database->connection();
        try {
            $count = $connection->transaction(function () use ($connection, $compiled, $limit): int {
                if ($connection->getDriverName() === 'pgsql') {
                    $timeout = max(1, min(10000, $compiled->timeoutMs));
                    $connection->statement('SET LOCAL statement_timeout = '.$timeout);
                }

                // LIMIT is applied before counting. No full-audience exact COUNT is issued.
                return (int) $connection->query()
                    ->fromSub((clone $compiled->query)->limit($limit + 1), 'bounded_members')
                    ->count();
            });
        } catch (Throwable) {
            $this->audit->record(
                workspaceId: $scope->workspaceId, action: 'segment.preview.unavailable',
                evidence: ['definition_hash' => $compiled->definitionHash, 'evaluation_id' => $compiled->evaluationFingerprint],
                brandId: $scope->brandId, actorId: $scope->actorId,
                subjectType: $segmentId === null ? null : 'segment_definition', subjectId: $segmentId,
            );

            return $this->metadata($scope, $compiled, $segmentId, $version) + [
                'status' => 'timeout_or_unavailable', 'count_kind' => 'unavailable', 'count' => null,
                'eligibility' => 'not_evaluated',
            ];
        }

        $result = $this->metadata($scope, $compiled, $segmentId, $version) + [
            'status' => $count === 0 ? 'empty' : ($count > $limit ? 'large_audience' : 'fresh'),
            'count_kind' => $count > $limit ? 'capped' : 'exact',
            'count' => min($count, $limit),
            'count_lower_bound' => $count > $limit ? $limit + 1 : null,
            'eligibility' => 'not_evaluated',
        ];
        $this->audit->record(
            workspaceId: $scope->workspaceId, action: 'segment.preview.count',
            evidence: [
                'definition_hash' => $compiled->definitionHash,
                'evaluation_id' => $compiled->evaluationFingerprint,
                'definition_version' => $version,
                'count_kind' => $result['count_kind'],
            ],
            brandId: $scope->brandId, actorId: $scope->actorId,
            subjectType: $segmentId === null ? null : 'segment_definition', subjectId: $segmentId,
        );

        return $result;
    }

    /** @return array<string, mixed> */
    private function metadata(TenantContext $scope, CompiledSegment $compiled, ?string $segmentId, ?int $version): array
    {
        return [
            'workspace_id' => $scope->workspaceId,
            'segment_id' => $segmentId,
            'definition_version' => $version,
            'definition_hash' => $compiled->definitionHash,
            'evaluation_id' => $compiled->evaluationFingerprint,
            'evaluated_at' => $compiled->evaluatedAt.' UTC',
            'source_freshness_at' => null,
            'cache_status' => 'disabled',
            'cache_key' => null,
            'estimated_cost' => $compiled->estimatedCost,
            'preview_members' => [],
            'eligibility_explanation' => 'Segment membership is distinct from delivery eligibility. Consent and suppression must be checked at send admission.',
        ];
    }

    /** @param array<string, mixed> $node @param list<string> $allowed */
    private function assertFields(array $node, array $allowed): void
    {
        if ($node['type'] === 'group') {
            foreach ($node['children'] as $child) {
                $this->assertFields($child, $allowed);
            }
        } elseif ($node['type'] === 'not') {
            $this->assertFields($node['child'], $allowed);
        } elseif ($node['type'] === 'attribute' && ! in_array($node['field'], $allowed, true)) {
            throw new SegmentDefinitionException('field_not_authorized', '$.root.field');
        }
    }
}
