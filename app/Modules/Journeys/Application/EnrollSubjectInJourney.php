<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class EnrollSubjectInJourney
{
    public function __construct(
        private DatabaseManager $database,
        private JourneyEnrollmentGuard $guard,
    ) {}

    /** @return array{id: string, enrollment_key: string, generation: int, status: string, duplicate: bool} */
    public function enroll(
        TenantContext $scope,
        string $journeyVersionId,
        string $subjectId,
        string $eventId,
        JourneyReentryPolicy $policy,
        ?int $maximumEnrollments = null,
    ): array {
        if ($subjectId === '' || $eventId === '' || strlen($eventId) > 191) {
            throw new InvalidArgumentException('Canonical subject and event identifiers are required.');
        }

        return $this->database->transaction(function () use ($scope, $journeyVersionId, $subjectId, $eventId, $policy, $maximumEnrollments): array {
            $version = $this->database->table('journey_versions')
                ->where('workspace_id', $scope->workspaceId)->where('id', $journeyVersionId)
                ->lockForUpdate()->first();
            if ($version === null || $version->status !== 'published') {
                throw new InvalidArgumentException('Published journey version is unavailable in this workspace.');
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

            $prior = $this->database->table('journey_enrollments')
                ->where('workspace_id', $scope->workspaceId)->where('journey_version_id', $journeyVersionId)
                ->where('subject_id', $subjectId)->orderByDesc('generation')->get();
            $latestStatus = $prior->first()?->status;
            $this->guard->assertReentryAllowed($policy, $prior->count(), $latestStatus, $maximumEnrollments);
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
                'reentry_policy' => $policy->value,
                'generation' => $generation,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'enrollment_key' => $key, 'generation' => $generation, 'status' => 'active', 'duplicate' => false];
        });
    }
}
