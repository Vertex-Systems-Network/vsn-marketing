<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Application\JourneyActionExecutor;
use App\Modules\Journeys\Application\ProcessJourneyNode;
use App\Modules\Journeys\Application\StartJourneyExecution;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

function queuedJourneyFixture(bool $withAction = false): array
{
    $slug = 'journey-queue-'.Str::lower(Str::random(10));
    $org = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
    $workspace = Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => $slug, 'slug' => $slug]);
    $workspaceId = (string) $workspace->getKey();
    $contactId = (string) Str::uuid();
    $eventTypeId = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    $journeyId = (string) Str::uuid();
    $versionId = (string) Str::uuid();
    $enrollmentId = (string) Str::uuid();
    $now = now();
    DB::table('contacts')->insert(['id' => $contactId, 'workspace_id' => $workspaceId, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('event_types')->insert(['id' => $eventTypeId, 'workspace_id' => $workspaceId, 'canonical_name' => 'customer.created', 'schema_version' => 1, 'created_at' => $now]);
    DB::table('customer_events')->insert([
        'id' => $eventId, 'workspace_id' => $workspaceId, 'event_type_id' => $eventTypeId, 'contact_id' => $contactId,
        'occurred_at' => $now, 'received_at' => $now, 'source' => 'test', 'schema_version' => 1,
        'subjects' => '{}', 'payload' => '{"tier":"gold"}', 'source_metadata' => '{}', 'created_at' => $now,
    ]);
    $graph = [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
            ['id' => 'branch', 'type' => 'branch', 'config' => ['field' => 'tier', 'operator' => 'equals', 'value' => 'gold']],
            ['id' => 'end', 'type' => 'end'],
            ['id' => 'exit', 'type' => 'exit', 'config' => ['event' => 'customer.created']],
        ],
        'edges' => [
            ['from' => 'trigger', 'to' => 'branch'],
            ['from' => 'branch', 'to' => 'end', 'type' => 'true'],
            ['from' => 'branch', 'to' => 'exit', 'type' => 'false'],
        ],
    ];
    if ($withAction) {
        $graph['nodes'][] = ['id' => 'action', 'type' => 'action', 'config' => ['capability' => 'test.action']];
        $graph['edges'][1]['to'] = 'action';
        $graph['edges'][] = ['from' => 'action', 'to' => 'end'];
    }
    $validator = app(JourneyGraphValidator::class);
    DB::table('journeys')->insert(['id' => $journeyId, 'workspace_id' => $workspaceId, 'name' => 'Queue', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('journey_versions')->insert([
        'id' => $versionId, 'workspace_id' => $workspaceId, 'journey_id' => $journeyId, 'version_number' => 1,
        'graph' => json_encode($validator->normalize($graph), JSON_THROW_ON_ERROR), 'definition_hash' => $validator->hash($graph),
        'status' => 'published', 'reentry_policy' => 'never', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('journey_enrollments')->insert([
        'id' => $enrollmentId, 'workspace_id' => $workspaceId, 'journey_version_id' => $versionId,
        'subject_id' => $contactId, 'trigger_event_id' => $eventId, 'enrollment_key' => hash('sha256', $enrollmentId),
        'generation' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['workspace_id' => $workspaceId, 'enrollment_id' => $enrollmentId];
}

it('runs a pinned trigger branch and end through durable node work without duplicate effects', function () {
    Queue::fake();
    $fixture = queuedJourneyFixture();
    $workspaceId = $fixture['workspace_id'];
    $executionId = app(StartJourneyExecution::class)->handle($workspaceId, $fixture['enrollment_id']);
    expect(app(StartJourneyExecution::class)->handle($workspaceId, $fixture['enrollment_id']))->toBe($executionId)
        ->and(DB::table('journey_work_items')->where('workspace_id', $workspaceId)->count())->toBe(1);

    foreach (['trigger', 'branch', 'end'] as $node) {
        $item = DB::table('journey_work_items')->where('workspace_id', $workspaceId)->where('execution_id', $executionId)->where('node_id', $node)->first();
        expect($item)->not->toBeNull();
        app(ProcessJourneyNode::class)->handle($workspaceId, (string) $item->id);
        app(ProcessJourneyNode::class)->handle($workspaceId, (string) $item->id);
    }

    expect(DB::table('journey_executions')->where('id', $executionId)->value('status'))->toBe('succeeded')
        ->and(DB::table('journey_work_items')->where('workspace_id', $workspaceId)->count())->toBe(3)
        ->and(DB::table('journey_node_attempts')->where('execution_id', $executionId)->where('status', 'succeeded')->count())->toBe(3)
        ->and(DB::table('journey_work_items')->where('node_id', 'trigger')->value('queue_age_us'))->not->toBeNull();
});

it('never consumes a work item under another workspace identity', function () {
    Queue::fake();
    $fixture = queuedJourneyFixture();
    $executionId = app(StartJourneyExecution::class)->handle($fixture['workspace_id'], $fixture['enrollment_id']);
    $itemId = DB::table('journey_work_items')->where('execution_id', $executionId)->value('id');
    app(ProcessJourneyNode::class)->handle((string) Str::uuid(), (string) $itemId);

    expect(DB::table('journey_work_items')->where('id', $itemId)->value('status'))->toBe('pending')
        ->and(DB::table('journey_node_attempts')->where('execution_id', $executionId)->count())->toBe(0);
});

it('classifies an action policy denial as known failure without invoking the adapter', function () {
    Queue::fake();
    $fixture = queuedJourneyFixture(true);
    $invocation = (object) ['called' => false];
    app()->instance(JourneyActionExecutor::class, new class($invocation) implements JourneyActionExecutor
    {
        public function __construct(private object $invocation) {}

        public function checks(string $workspaceId, string $subjectId, array $node): array
        {
            return ['provider_capability' => false];
        }

        public function execute(string $workspaceId, string $subjectId, array $node, string $attemptKey): void
        {
            $this->invocation->called = true;
        }
    });
    $execution = app(StartJourneyExecution::class)->handle($fixture['workspace_id'], $fixture['enrollment_id']);
    foreach (['trigger', 'branch'] as $node) {
        $item = DB::table('journey_work_items')->where('execution_id', $execution)->where('node_id', $node)->first();
        app(ProcessJourneyNode::class)->handle($fixture['workspace_id'], (string) $item->id);
    }
    $item = DB::table('journey_work_items')->where('execution_id', $execution)->where('node_id', 'action')->first();
    expect(fn () => app(ProcessJourneyNode::class)->handle($fixture['workspace_id'], (string) $item->id))
        ->toThrow(JourneyDefinitionException::class, 'action_blocked');

    expect($invocation->called)->toBeFalse()
        ->and(DB::table('journey_executions')->where('id', $execution)->value('status'))->toBe('failed')
        ->and(DB::table('journey_node_attempts')->where('execution_id', $execution)->where('node_id', 'action')->value('status'))->toBe('dead_letter');
});

it('holds an invoked action with an ambiguous exception for operator review', function () {
    Queue::fake();
    $fixture = queuedJourneyFixture(true);
    app()->instance(JourneyActionExecutor::class, new class implements JourneyActionExecutor
    {
        public function checks(string $workspaceId, string $subjectId, array $node): array
        {
            return array_fill_keys(['provider_capability', 'consent', 'suppression_clear', 'authorized', 'quota_available', 'idempotent'], true);
        }

        public function execute(string $workspaceId, string $subjectId, array $node, string $attemptKey): void
        {
            throw new RuntimeException('ambiguous action outcome');
        }
    });
    $execution = app(StartJourneyExecution::class)->handle($fixture['workspace_id'], $fixture['enrollment_id']);
    foreach (['trigger', 'branch'] as $node) {
        $item = DB::table('journey_work_items')->where('execution_id', $execution)->where('node_id', $node)->first();
        app(ProcessJourneyNode::class)->handle($fixture['workspace_id'], (string) $item->id);
    }
    $item = DB::table('journey_work_items')->where('execution_id', $execution)->where('node_id', 'action')->first();
    expect(fn () => app(ProcessJourneyNode::class)->handle($fixture['workspace_id'], (string) $item->id))
        ->toThrow(RuntimeException::class, 'ambiguous action outcome');

    expect(DB::table('journey_executions')->where('id', $execution)->value('status'))->toBe('blocked')
        ->and(DB::table('journey_node_attempts')->where('execution_id', $execution)->where('node_id', 'action')->value('status'))->toBe('operator_review');
});
