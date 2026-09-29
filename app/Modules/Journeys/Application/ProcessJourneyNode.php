<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\Contracts\JourneyWaitRepository;
use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyAttemptPolicy;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyGraphTraversal;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;
use App\Modules\Journeys\Domain\JourneyWaitEvaluator;
use App\Modules\Journeys\Domain\JourneyWaitOutcome;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/** Consumes one durable graph node. Duplicate Redis deliveries observe the same work identity. */
final readonly class ProcessJourneyNode
{
    public function __construct(
        private DatabaseManager $database,
        private JourneyNodeAttemptRepository $attempts,
        private JourneyWaitRepository $waits,
        private JourneyGraphValidator $validator,
        private JourneyGraphTraversal $traversal,
        private DispatchJourneyAction $actionGate,
        private JourneyActionExecutor $actions,
        private JourneyWaitEvaluator $waitEvaluator,
    ) {}

    public function handle(string $workspaceId, string $workItemId): void
    {
        $policy = new JourneyAttemptPolicy(maxWorkspaceConcurrent: (int) config('journeys.max_workspace_concurrent_attempts', 1));
        $now = new DateTimeImmutable('now');
        $work = $this->database->transaction(function () use ($workspaceId, $workItemId, $now, $policy): ?object {
            $row = $this->database->table('journey_work_items')
                ->where('workspace_id', $workspaceId)->where('id', $workItemId)->lockForUpdate()->first();
            if ($row === null || in_array($row->status, ['completed', 'cancelled', 'blocked'], true)
                || new DateTimeImmutable((string) $row->available_at) > $now) {
                return null;
            }
            $execution = $this->database->table('journey_executions')
                ->where('workspace_id', $workspaceId)->where('id', $row->execution_id)->first();
            if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited', 'failed', 'blocked'], true)) {
                $this->database->table('journey_work_items')->where('id', $workItemId)->where('workspace_id', $workspaceId)
                    ->update(['status' => 'cancelled', 'updated_at' => $now]);

                return null;
            }
            if ($row->status === 'waiting') {
                return $row;
            }
            $age = max(0, (int) round(((float) $now->format('U.u') - (float) (new DateTimeImmutable((string) $row->enqueued_at))->format('U.u')) * 1000000));
            $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                ->update([
                    'status' => 'running', 'started_at' => $now, 'queue_age_us' => $age,
                    'available_at' => $now->modify('+'.$policy->leaseSeconds.' seconds'), 'updated_at' => $now,
                ]);
            $row->status = 'running';

            return $row;
        });
        if ($work === null) {
            return;
        }

        try {
            $context = $this->loadContext($workspaceId, (string) $work->execution_id, (string) $work->node_id);
        } catch (Throwable $error) {
            $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                ->update(['status' => 'blocked', 'updated_at' => now()]);
            throw $error;
        }
        if ($work->status === 'waiting') {
            $this->resumeWait($workspaceId, $workItemId, $context, $now);

            return;
        }
        $claim = $this->attempts->claim($workspaceId, (string) $work->execution_id, (string) $work->node_id, 1, $now, $policy);
        if ($claim === null) {
            return; // A bounded recovery sweep will redispatch when the lease or workspace budget permits.
        }

        try {
            if ($context['node']['type'] === 'action') {
                $this->actionGate->handle(
                    $this->actions->checks($workspaceId, $context['subject_id'], $context['node']),
                    fn () => $this->actions->execute($workspaceId, $context['subject_id'], $context['node'], $claim['attempt_key']),
                );
            }
            $this->database->transaction(function () use ($workspaceId, $workItemId, $context, $claim, $now): void {
                $current = $this->database->table('journey_work_items')
                    ->where('workspace_id', $workspaceId)->where('id', $workItemId)->lockForUpdate()->first();
                if ($current === null || $current->status !== 'running'
                    || ! $this->attempts->complete($workspaceId, $context['execution_id'], $claim['attempt_key'], $claim['lease_token'], new DateTimeImmutable('now'))) {
                    return;
                }
                $node = $context['node'];
                if ($node['type'] === 'wait') {
                    $wait = DurableJourneyWait::schedule($workspaceId, $context['execution_id'], $node['id'], $now, $node['config']['seconds'], new JourneyRuntimePolicy);
                    $predicate = isset($node['config']['field']) ? array_intersect_key($node['config'], array_flip(['field', 'operator', 'value'])) : null;
                    $this->waits->store($wait, $predicate);
                    $nextCheck = $predicate === null ? $wait->wakeAt : min($wait->wakeAt, $now->modify('+60 seconds'));
                    $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                        ->update(['status' => 'waiting', 'available_at' => $nextCheck, 'updated_at' => now()]);
                    $this->transitionExecution($workspaceId, $context['execution_id'], 'waiting', $node['id']);

                    return;
                }
                $terminal = match ($node['type']) {
                    'end' => 'succeeded',
                    'goal' => $context['event_type'] === $node['config']['event'] ? 'succeeded' : 'blocked',
                    'exit' => $context['event_type'] === $node['config']['event'] ? 'exited' : 'blocked',
                    default => null,
                };
                if ($terminal !== null) {
                    $this->finishExecution($workspaceId, $context['execution_id'], $terminal, $node['id']);
                } else {
                    $this->queueSuccessors($workspaceId, $context);
                }
                $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                    ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable $error) {
            $this->attempts->fail($workspaceId, $context['execution_id'], $claim['attempt_key'], $claim['lease_token'],
                ['code' => 'node_execution_failed', 'category' => 'runtime'], false, $context['node']['type'] === 'action', $policy, new DateTimeImmutable('now'));
            $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                ->update(['status' => 'blocked', 'updated_at' => now()]);
            throw $error;
        }
    }

    /** @return array{execution_id:string,subject_id:string,node:array<string,mixed>,graph:array<string,mixed>,event_type:string,attributes:array<string,mixed>} */
    private function loadContext(string $workspaceId, string $executionId, string $nodeId): array
    {
        $execution = $this->database->table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->first();
        $version = $execution === null ? null : $this->database->table('journey_versions')
            ->where('workspace_id', $workspaceId)->where('id', $execution->journey_version_id)->first();
        $enrollment = $execution === null ? null : $this->database->table('journey_enrollments')
            ->where('workspace_id', $workspaceId)->where('id', $execution->enrollment_id)->first();
        $event = $enrollment === null ? null : $this->database->table('customer_events')
            ->join('event_types', function ($join): void {
                $join->on('event_types.id', '=', 'customer_events.event_type_id')
                    ->on('event_types.workspace_id', '=', 'customer_events.workspace_id');
            })
            ->where('customer_events.workspace_id', $workspaceId)->where('customer_events.id', $enrollment->trigger_event_id)
            ->select('customer_events.payload', 'event_types.canonical_name')->first();
        if ($version === null || $event === null) {
            throw new JourneyDefinitionException('pinned_execution_context_missing', '$.execution');
        }
        $graph = json_decode((string) $version->graph, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($graph) || ! hash_equals((string) $version->definition_hash, $this->validator->hash($graph))) {
            throw new JourneyDefinitionException('pinned_graph_integrity_failed', '$.version');
        }
        foreach ($graph['nodes'] as $node) {
            if ($node['id'] === $nodeId) {
                $attributes = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($attributes)) {
                    throw new JourneyDefinitionException('invalid_event_attributes', '$.event');
                }

                return ['execution_id' => $executionId, 'subject_id' => (string) $execution->subject_id,
                    'node' => $node, 'graph' => $graph, 'event_type' => (string) $event->canonical_name, 'attributes' => $attributes];
            }
        }
        throw new JourneyDefinitionException('pinned_node_missing', '$.nodes');
    }

    /** @param array<string, mixed> $context */
    private function queueSuccessors(string $workspaceId, array $context): void
    {
        foreach ($this->traversal->successors($context['graph'], $context['node']['id'], $context['attributes']) as $next) {
            $id = (string) Str::uuid();
            $inserted = $this->database->table('journey_work_items')->insertOrIgnore([
                'id' => $id, 'workspace_id' => $workspaceId, 'execution_id' => $context['execution_id'],
                'node_id' => $next, 'status' => 'pending', 'available_at' => now(), 'enqueued_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 1) {
                JourneyNodeJob::dispatch($workspaceId, $id);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function resumeWait(string $workspaceId, string $workItemId, array $context, DateTimeImmutable $now): void
    {
        $waitRow = $this->database->table('journey_waits')->where('workspace_id', $workspaceId)
            ->where('execution_id', $context['execution_id'])->where('node_id', $context['node']['id'])->where('status', 'pending')->first();
        if ($waitRow === null) {
            return;
        }
        $wait = DurableJourneyWait::restore($workspaceId, $context['execution_id'], $context['node']['id'],
            new DateTimeImmutable((string) $waitRow->wake_at), (string) $waitRow->wait_key);
        $predicate = $waitRow->predicate === null ? null : json_decode((string) $waitRow->predicate, true, 512, JSON_THROW_ON_ERROR);
        if ($this->waitEvaluator->evaluate($wait, $workspaceId, $now, $context['attributes'], $predicate) === JourneyWaitOutcome::Waiting) {
            $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                ->where('status', 'waiting')->update(['available_at' => min($wait->wakeAt, $now->modify('+60 seconds')), 'updated_at' => now()]);

            return;
        }
        $this->database->transaction(function () use ($workspaceId, $workItemId, $context, $waitRow, $now): void {
            $execution = $this->database->table('journey_executions')->where('workspace_id', $workspaceId)
                ->where('id', $context['execution_id'])->lockForUpdate()->first();
            if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited', 'blocked', 'failed'], true)
                || ! $this->waits->markResumed($workspaceId, (string) $waitRow->wait_key, $now)) {
                return;
            }
            $this->queueSuccessors($workspaceId, $context);
            $this->transitionExecution($workspaceId, $context['execution_id'], 'running', $context['node']['id']);
            $this->database->table('journey_work_items')->where('workspace_id', $workspaceId)->where('id', $workItemId)
                ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        });
    }

    private function finishExecution(string $workspaceId, string $executionId, string $status, string $nodeId): void
    {
        $this->transitionExecution($workspaceId, $executionId, $status, $nodeId);
    }

    private function transitionExecution(string $workspaceId, string $executionId, string $status, string $nodeId): void
    {
        $execution = $this->database->table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)->lockForUpdate()->first();
        if ($execution === null || in_array($execution->status, ['cancelled', 'succeeded', 'exited'], true)) {
            throw new JourneyDefinitionException('execution_not_running', '$.execution');
        }
        $revision = (int) $execution->revision + 1;
        $this->database->table('journey_executions')->where('workspace_id', $workspaceId)->where('id', $executionId)
            ->update(['status' => $status, 'revision' => $revision, 'updated_at' => now()]);
        $this->database->table('journey_execution_transitions')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'execution_id' => $executionId,
            'transition_revision' => $revision, 'event_type' => 'execution_'.$status, 'node_id' => $nodeId,
            'attempt' => null, 'metadata' => null, 'created_at' => now(),
        ]);
    }
}
