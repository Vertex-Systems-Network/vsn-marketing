<?php

namespace App\Modules\Journeys\Infrastructure\Persistence;

use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\JourneyAttemptPolicy;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseJourneyNodeAttemptRepository implements JourneyNodeAttemptRepository
{
    public function claim(string $workspaceId, string $executionId, string $nodeId, int $attempt, DateTimeImmutable $now, JourneyAttemptPolicy $policy): ?array
    {
        if ($attempt < 1 || $nodeId === '' || strlen($nodeId) > 128) {
            throw new JourneyDefinitionException('invalid_node_attempt', '$.node_attempt');
        }
        $key = JourneyExecutionIdentity::nodeAttempt($executionId, $nodeId, $attempt);

        return DB::transaction(function () use ($workspaceId, $executionId, $nodeId, $attempt, $now, $policy, $key): ?array {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            if ($workspace === null) {
                return null;
            }
            $execution = DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->lockForUpdate()->first();
            if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited'], true)) {
                return null;
            }

            $row = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('attempt_key', $key)->lockForUpdate()->first();
            $leaseUntil = $now->modify('+'.$policy->leaseSeconds.' seconds');
            $token = (string) Str::uuid();
            $running = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('status', 'running')->where('lease_until', '>', $now)->count();
            if ($running >= $policy->maxWorkspaceConcurrent) {
                return null;
            }

            if ($row === null) {
                $id = (string) Str::uuid();
                DB::table('journey_node_attempts')->insert([
                    'id' => $id,
                    'workspace_id' => $workspaceId,
                    'execution_id' => $executionId,
                    'node_id' => $nodeId,
                    'attempt' => $attempt,
                    'attempt_key' => $key,
                    'status' => 'running',
                    'lease_until' => $leaseUntil,
                    'lease_token' => $token,
                    'lease_generation' => 1,
                    'available_at' => $now,
                    'error' => null,
                    'error_class' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                if (! in_array($row->status, ['queued', 'retryable', 'running'], true)
                    || ($row->available_at !== null && new DateTimeImmutable($row->available_at) > $now)
                    || ($row->status === 'running' && ($row->lease_until === null || new DateTimeImmutable($row->lease_until) > $now))) {
                    return null;
                }

                $updated = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('id', $row->id)
                    ->where('status', $row->status)->update([
                        'status' => 'running',
                        'lease_until' => $leaseUntil,
                        'lease_token' => $token,
                        'lease_generation' => DB::raw('lease_generation + 1'),
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    return null;
                }
                $id = (string) $row->id;
            }

            $revision = (int) $execution->revision + 1;
            DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->update([
                'status' => in_array($execution->status, ['queued', 'blocked', 'failed'], true) ? 'running' : $execution->status,
                'revision' => $revision,
                'updated_at' => $now,
            ]);
            $this->recordTransition($workspaceId, $executionId, $revision, 'attempt_claimed', $nodeId, $attempt, [], $now);

            return ['id' => $id, 'attempt_key' => $key, 'lease_token' => $token, 'status' => 'running', 'lease_until' => $leaseUntil->format(DATE_ATOM)];
        });
    }

    public function complete(string $workspaceId, string $executionId, string $attemptKey, string $leaseToken, DateTimeImmutable $now): bool
    {
        return DB::transaction(function () use ($workspaceId, $executionId, $attemptKey, $leaseToken, $now): bool {
            $execution = DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->lockForUpdate()->first();
            if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited'], true)) {
                return false;
            }
            $changed = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('execution_id', $executionId)
                ->where('attempt_key', $attemptKey)->where('lease_token', $leaseToken)->where('status', 'running')
                ->where('lease_until', '>', $now)->update([
                    'status' => 'succeeded', 'lease_until' => null, 'lease_token' => null, 'completed_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                return false;
            }
            $revision = (int) $execution->revision + 1;
            DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->update([
                'revision' => $revision, 'updated_at' => $now,
            ]);
            $attempt = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('attempt_key', $attemptKey)->first();
            $this->recordTransition($workspaceId, $executionId, $revision, 'attempt_succeeded', (string) $attempt->node_id, (int) $attempt->attempt, [], $now);

            return true;
        });
    }

    public function fail(string $workspaceId, string $executionId, string $attemptKey, string $leaseToken, array $error, bool $retryable, bool $outcomeUnknown, JourneyAttemptPolicy $policy, DateTimeImmutable $now): string
    {
        $safeError = [];
        foreach (['code', 'category'] as $field) {
            $value = $error[$field] ?? null;
            if (is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $value) === 1) {
                $safeError[$field] = $value;
            }
        }
        $encodedError = json_encode($safeError, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return DB::transaction(function () use ($workspaceId, $executionId, $attemptKey, $leaseToken, $encodedError, $safeError, $retryable, $outcomeUnknown, $policy, $now): string {
            $execution = DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->lockForUpdate()->first();
            if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited'], true)) {
                return 'stale';
            }
            $attemptRow = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('execution_id', $executionId)
                ->where('attempt_key', $attemptKey)->where('lease_token', $leaseToken)->where('status', 'running')
                ->where('lease_until', '>', $now)->lockForUpdate()->first();
            if ($attemptRow === null) {
                return 'stale';
            }
            $status = $outcomeUnknown ? 'operator_review' : ($retryable && (int) $attemptRow->attempt < $policy->maxAttempts ? 'retryable' : 'dead_letter');
            $changed = DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('execution_id', $executionId)
                ->where('attempt_key', $attemptKey)->where('lease_token', $leaseToken)->where('status', 'running')
                ->where('lease_until', '>', $now)->update([
                    'status' => $status,
                    'lease_until' => null,
                    'lease_token' => null,
                    'available_at' => $status === 'retryable' ? $now->modify('+'.$policy->retryDelaySeconds.' seconds') : null,
                    'error' => $encodedError,
                    'error_class' => $status === 'operator_review' ? 'unknown_outcome' : ($retryable ? 'retryable' : 'non_retryable'),
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                return 'stale';
            }
            $revision = (int) $execution->revision + 1;
            DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->update([
                'status' => $status === 'retryable' ? 'queued' : ($status === 'operator_review' ? 'blocked' : 'failed'),
                'revision' => $revision, 'updated_at' => $now,
            ]);
            $this->recordTransition($workspaceId, $executionId, $revision, 'attempt_'.$status, (string) $attemptRow->node_id, (int) $attemptRow->attempt, $safeError, $now);

            return $status;
        });
    }

    public function cancelExecution(string $workspaceId, string $executionId, DateTimeImmutable $now): bool
    {
        return DB::transaction(function () use ($workspaceId, $executionId, $now): bool {
            $execution = DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->lockForUpdate()->first();
            if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited'], true)) {
                return false;
            }
            $revision = (int) $execution->revision + 1;
            DB::table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->update([
                'status' => 'cancelled', 'revision' => $revision, 'updated_at' => $now,
            ]);
            DB::table('journey_node_attempts')->where('workspace_id', $workspaceId)->where('execution_id', $executionId)
                ->whereIn('status', ['queued', 'running', 'retryable'])->update([
                    'status' => 'cancelled', 'lease_until' => null, 'lease_token' => null, 'updated_at' => $now,
                ]);
            $this->recordTransition($workspaceId, $executionId, $revision, 'execution_cancelled', null, null, [], $now);

            return true;
        });
    }

    /** @param array<string, mixed> $metadata */
    private function recordTransition(string $workspaceId, string $executionId, int $revision, string $eventType, ?string $nodeId, ?int $attempt, array $metadata, DateTimeImmutable $now): void
    {
        DB::table('journey_execution_transitions')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'execution_id' => $executionId,
            'transition_revision' => $revision,
            'event_type' => $eventType,
            'node_id' => $nodeId,
            'attempt' => $attempt,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);
    }
}
