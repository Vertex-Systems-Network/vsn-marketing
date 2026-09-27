<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyEnrollmentAdmissionPolicy;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class EnrollSubjectInJourney
{
    public function __construct(
        private DatabaseManager $database,
        private JourneyEnrollmentGuard $guard,
        private JourneyGraphValidator $validator,
        private JourneyEnrollmentAdmissionPolicy $admissionPolicy,
    ) {}

    /** @return array{id: string, enrollment_key: string, generation: int, status: string, duplicate: bool} */
    public function enroll(
        TenantContext $scope,
        string $journeyVersionId,
        string $subjectId,
        string $eventId,
    ): array {
        if ($subjectId === '' || $eventId === '' || strlen($eventId) > 191) {
            throw new InvalidArgumentException('Canonical subject and event identifiers are required.');
        }

        return $this->database->transaction(function () use ($scope, $journeyVersionId, $subjectId, $eventId): array {
            $workspace = $this->database->table('workspaces')
                ->where('id', $scope->workspaceId)->lockForUpdate()->first();
            if ($workspace === null) {
                throw new InvalidArgumentException('Enrollment workspace is unavailable.');
            }

            $version = $this->database->table('journey_versions')
                ->where('workspace_id', $scope->workspaceId)->where('id', $journeyVersionId)
                ->lockForUpdate()->first();
            if ($version === null || $version->status !== 'published') {
                throw new InvalidArgumentException('Published journey version is unavailable in this workspace.');
            }
            $graph = is_string($version->graph) ? json_decode($version->graph, true) : null;
            if (! is_array($graph) || $this->validator->hash($graph) !== $version->definition_hash) {
                throw new JourneyDefinitionException('published_version_integrity_failed', '$.version');
            }
            $policy = JourneyReentryPolicy::tryFrom((string) $version->reentry_policy);
            if ($policy === null) {
                throw new InvalidArgumentException('Published journey version has an unsupported re-entry policy.');
            }

            $key = $this->guard->key($scope->workspaceId, $journeyVersionId, $subjectId, ['event_id' => $eventId]);
            $existing = $this->database->table('journey_enrollments')
                ->where('workspace_id', $scope->workspaceId)->where('journey_version_id', $journeyVersionId)
                ->where('subject_id', $subjectId)->where('trigger_event_id', $eventId)->first();
            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'enrollment_key' => (string) $existing->enrollment_key,
                    'generation' => (int) $existing->generation,
                    'status' => (string) $existing->status,
                    'duplicate' => true,
                ];
            }

            $activeEnrollments = $this->database->table('journey_enrollments')
                ->where('workspace_id', $scope->workspaceId)->where('status', 'active')->count();
            $this->guard->assertWorkspaceAdmissionAllowed((int) $activeEnrollments, $this->admissionPolicy);

            $prior = $this->database->table('journey_enrollments')
                ->where('workspace_id', $scope->workspaceId)->where('journey_version_id', $journeyVersionId)
                ->where('subject_id', $subjectId)->orderByDesc('generation')->get();
            $latestStatus = $prior->first()?->status;
            $this->guard->assertReentryAllowed($policy, $prior->count(), $latestStatus, $version->max_enrollments === null ? null : (int) $version->max_enrollments);
            $generation = $prior->count() + 1;
            $id = (string) Str::uuid();
            $now = now();
            $this->database->table('journey_enrollments')->insert([
                'id' => $id,
                'workspace_id' => $scope->workspaceId,
                'journey_version_id' => $journeyVersionId,
                'subject_id' => $subjectId,
                'trigger_event_id' => $eventId,
                'enrollment_key' => $key,
                'generation' => $generation,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'enrollment_key' => $key, 'generation' => $generation, 'status' => 'active', 'duplicate' => false];
        });
    }
}
