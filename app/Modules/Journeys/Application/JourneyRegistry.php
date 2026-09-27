<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Journeys\Domain\JourneyDefinition;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final readonly class JourneyRegistry
{
    public function __construct(
        private JourneyGraphValidator $validator,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $graph
     * @return array{definition: JourneyDefinition, version_number: int}
     */
    public function publish(
        string $journeyId,
        int $versionNumber,
        TenantContext $scope,
        User $actor,
        array $graph,
        bool $confirmed,
        JourneyReentryPolicy $reentryPolicy = JourneyReentryPolicy::Never,
        ?int $maximumEnrollments = null,
    ): array {
        if (! $confirmed || (string) $actor->getKey() !== $scope->actorId
            || ! $this->authorizer->allows($actor, $scope, PermissionCatalog::JOURNEY_PUBLISH)) {
            throw new AuthorizationException('Journey publication requires confirmation and workspace publish permission.');
        }
        if ($versionNumber < 1 || $journeyId === '') {
            throw new \InvalidArgumentException('Journey and positive version number are required.');
        }
        if (($reentryPolicy === JourneyReentryPolicy::Bounded && ($maximumEnrollments === null || $maximumEnrollments < 1))
            || ($reentryPolicy !== JourneyReentryPolicy::Bounded && $maximumEnrollments !== null)) {
            throw new \InvalidArgumentException('Re-entry limits must match the immutable version policy.');
        }
        $record = $this->database->table('journeys')
            ->where('workspace_id', $scope->workspaceId)->where('id', $journeyId)->first();
        if ($record === null) {
            throw new \InvalidArgumentException('Workspace journey does not exist.');
        }
        $definition = JourneyDefinition::publish($scope->workspaceId, (string) Str::uuid(), $graph, $this->validator);
        $canonical = json_encode($definition->graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->database->transaction(function () use ($scope, $journeyId, $versionNumber, $definition, $canonical, $reentryPolicy, $maximumEnrollments): void {
            $exists = $this->database->table('journey_versions')
                ->where('workspace_id', $scope->workspaceId)->where('journey_id', $journeyId)
                ->where('version_number', $versionNumber)->exists();
            if ($exists) {
                throw new \InvalidArgumentException('Journey versions are immutable and cannot be republished.');
            }
            $this->database->table('journey_versions')->insert([
                'id' => $definition->versionId,
                'workspace_id' => $scope->workspaceId,
                'journey_id' => $journeyId,
                'version_number' => $versionNumber,
                'graph' => $canonical,
                'definition_hash' => $definition->hash,
                'reentry_policy' => $reentryPolicy->value,
                'max_enrollments' => $maximumEnrollments,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->audit->record(
            workspaceId: $scope->workspaceId,
            action: 'journey.version.published',
            evidence: ['definition_hash' => $definition->hash, 'version_number' => $versionNumber],
            brandId: $scope->brandId,
            actorId: $scope->actorId,
            subjectType: 'journey',
            subjectId: $journeyId,
        );

        return [
            'definition' => $definition,
            'version_number' => $versionNumber,
        ];
    }
}
