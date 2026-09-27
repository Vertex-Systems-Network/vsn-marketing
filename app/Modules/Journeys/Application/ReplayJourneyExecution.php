<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReplayJourneyExecution
{
    public function __construct(private DatabaseManager $database) {}

    /** @return array{id: string, replay_of: string, journey_version_id: string, execution_key: string, status: string, duplicate: bool} */
    public function handle(TenantContext $scope, string $sourceExecutionId, string $replayRequestId): array
    {
        if ($replayRequestId === '' || strlen($replayRequestId) > 191) {
            throw new InvalidArgumentException('A bounded replay request identity is required.');
        }

        return $this->database->transaction(function () use ($scope, $sourceExecutionId, $replayRequestId): array {
            $source = $this->database->table('journey_executions')
                ->where('workspace_id', $scope->workspaceId)->where('id', $sourceExecutionId)
                ->lockForUpdate()->first();
            if ($source === null || ! in_array($source->status, ['failed', 'blocked', 'cancelled', 'succeeded', 'exited'], true)) {
                throw new InvalidArgumentException('Only a terminal execution in the active workspace can be replayed.');
            }
            Gate::authorize('replay-journey-execution', [$scope, $source]);

            $version = $this->database->table('journey_versions')
                ->where('workspace_id', $scope->workspaceId)->where('id', $source->journey_version_id)->first();
            if ($version === null) {
                throw new InvalidArgumentException('Pinned journey version is unavailable in this workspace.');
            }

            $key = hash('sha256', json_encode([
                'replay', $scope->workspaceId, $sourceExecutionId, $scope->actorId, $replayRequestId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $existing = $this->database->table('journey_executions')
                ->where('workspace_id', $scope->workspaceId)->where('execution_key', $key)->first();
            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'replay_of' => $sourceExecutionId,
                    'journey_version_id' => (string) $existing->journey_version_id,
                    'execution_key' => $key,
                    'status' => (string) $existing->status,
                    'duplicate' => true,
                ];
            }

            $id = (string) Str::uuid();
            $now = now();
            $this->database->table('journey_executions')->insert([
                'id' => $id,
                'workspace_id' => $scope->workspaceId,
                'journey_version_id' => $source->journey_version_id,
                'subject_id' => $source->subject_id,
                'enrollment_id' => $source->enrollment_id,
                'execution_key' => $key,
                'status' => 'queued',
                'revision' => 0,
                'transition_history' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->database->table('journey_execution_transitions')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $scope->workspaceId,
                'execution_id' => $id,
                'transition_revision' => 0,
                'event_type' => 'execution_replayed',
                'node_id' => null,
                'attempt' => null,
                'metadata' => json_encode([
                    'source_execution_id' => $sourceExecutionId,
                    'source_transition_revision' => (int) $source->revision,
                    'journey_version_id' => (string) $source->journey_version_id,
                    'definition_hash' => (string) $version->definition_hash,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
            ]);

            return [
                'id' => $id,
                'replay_of' => $sourceExecutionId,
                'journey_version_id' => (string) $source->journey_version_id,
                'execution_key' => $key,
                'status' => 'queued',
                'duplicate' => false,
            ];
        });
    }
}
