<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use App\Modules\Journeys\Domain\JourneyGraphTraversal;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/** Starts a single pinned enrollment with one durable queue identity. */
final readonly class StartJourneyExecution
{
    public function __construct(
        private DatabaseManager $database,
        private JourneyGraphValidator $validator,
        private JourneyGraphTraversal $traversal,
    ) {}

    public function handle(string $workspaceId, string $enrollmentId): string
    {
        if ($workspaceId === '' || $enrollmentId === '') {
            throw new JourneyDefinitionException('execution_scope_required', '$.execution');
        }

        return $this->database->transaction(function () use ($workspaceId, $enrollmentId): string {
            $enrollment = $this->database->table('journey_enrollments')
                ->where('workspace_id', $workspaceId)->where('id', $enrollmentId)->lockForUpdate()->first();
            if ($enrollment === null || $enrollment->status !== 'active') {
                throw new JourneyDefinitionException('active_enrollment_required', '$.enrollment');
            }
            $version = $this->database->table('journey_versions')
                ->where('workspace_id', $workspaceId)->where('id', $enrollment->journey_version_id)->first();
            $event = $this->database->table('customer_events')
                ->join('event_types', function ($join): void {
                    $join->on('event_types.id', '=', 'customer_events.event_type_id')
                        ->on('event_types.workspace_id', '=', 'customer_events.workspace_id');
                })
                ->where('customer_events.workspace_id', $workspaceId)->where('customer_events.id', $enrollment->trigger_event_id)
                ->select('event_types.canonical_name')->first();
            if ($version === null || $event === null || $version->status !== 'published') {
                throw new JourneyDefinitionException('pinned_version_or_event_missing', '$.enrollment');
            }
            $graph = json_decode((string) $version->graph, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($graph) || ! hash_equals((string) $version->definition_hash, $this->validator->hash($graph))) {
                throw new JourneyDefinitionException('pinned_graph_integrity_failed', '$.version');
            }
            $entry = $this->traversal->entry($graph, (string) $event->canonical_name);
            $key = JourneyExecutionIdentity::for($workspaceId, (string) $version->id, (string) $enrollment->subject_id, $enrollmentId);
            $existing = $this->database->table('journey_executions')->where('workspace_id', $workspaceId)->where('execution_key', $key)->first();
            $executionId = $existing === null ? (string) Str::uuid() : (string) $existing->id;
            if ($existing === null) {
                $this->database->table('journey_executions')->insert([
                    'id' => $executionId, 'workspace_id' => $workspaceId, 'journey_version_id' => $version->id,
                    'subject_id' => $enrollment->subject_id, 'enrollment_id' => $enrollmentId,
                    'execution_key' => $key, 'status' => 'queued', 'revision' => 0,
                    'transition_history' => '[]', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($existing !== null && in_array($existing->status, ['cancelled', 'succeeded', 'exited', 'failed', 'blocked'], true)) {
                return $executionId;
            }
            $itemId = (string) Str::uuid();
            $inserted = $this->database->table('journey_work_items')->insertOrIgnore([
                'id' => $itemId, 'workspace_id' => $workspaceId, 'execution_id' => $executionId,
                'node_id' => $entry, 'status' => 'pending', 'available_at' => now(),
                'enqueued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 1) {
                JourneyNodeJob::dispatch($workspaceId, $itemId);
            }

            return $executionId;
        });
    }
}
