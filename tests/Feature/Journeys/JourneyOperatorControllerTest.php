<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function task0052JourneyOperatorFixture(): array
{
    $suffix = Str::lower(Str::random(10));
    $user = User::query()->create([
        'name' => 'Journey Operator',
        'email' => "journey-operator-{$suffix}@example.test",
        'password' => Hash::make('journey-operator-password'),
    ]);
    $organization = Organization::query()->create(['name' => "Journey Org {$suffix}", 'slug' => "journey-org-{$suffix}"]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(), 'name' => "Journey Workspace {$suffix}", 'slug' => "journey-workspace-{$suffix}",
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $workspace->getKey());
    $role = $roles->createRole((string) $workspace->getKey(), "journey-operator-{$suffix}", 'Journey Operator');
    foreach ([PermissionCatalog::JOURNEY_READ, PermissionCatalog::JOURNEY_CREATE, PermissionCatalog::JOURNEY_PUBLISH] as $permission) {
        $roles->grantPermission($role, $permission);
    }
    $roles->assignRole($membership, $role);
    $journeyId = (string) Str::uuid();
    DB::table('journeys')->insert([
        'id' => $journeyId,
        'workspace_id' => $workspace->getKey(),
        'name' => 'Welcome',
        'status' => 'draft',
        'draft_graph' => json_encode([
            'schema_version' => 1,
            'nodes' => [
                ['id' => 'entry', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
                ['id' => 'finish', 'type' => 'end'],
            ],
            'edges' => [['from' => 'entry', 'to' => 'finish']],
        ], JSON_THROW_ON_ERROR),
        'draft_revision' => 1,
        'lifecycle_revision' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('user', 'workspace', 'journeyId');
}

it('keeps journey authoring and lifecycle transitions workspace-scoped and revision-confirmed', function () {
    $this->withoutVite();
    $fixture = task0052JourneyOperatorFixture();
    $workspace = (string) $fixture['workspace']->getKey();
    $base = "/workspaces/{$workspace}/journeys/{$fixture['journeyId']}";

    $this->actingAs($fixture['user'])
        ->get("/workspaces/{$workspace}/journeys")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('journeys/operator')
            ->where('workspace_id', $workspace)
            ->has('journeys', 1)
            ->where('journeys.0.id', $fixture['journeyId'])
            ->where('journeys.0.draft_revision', 1)
            ->where('timeline', [])
        );

    $graph = [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'entry', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
            ['id' => 'delay', 'type' => 'wait', 'config' => ['seconds' => 60]],
            ['id' => 'finish', 'type' => 'end'],
        ],
        'edges' => [['from' => 'entry', 'to' => 'delay'], ['from' => 'delay', 'to' => 'finish']],
    ];
    $this->post("{$base}/draft", ['name' => 'Welcome v2', 'graph' => $graph, 'expected_revision' => 1])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'saved'));
    expect(DB::table('journeys')->where('id', $fixture['journeyId'])->value('draft_revision'))->toBe(2);

    $this->post("{$base}/publish", ['expected_revision' => 2, 'confirmed' => false])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.code', 'explicit_publish_confirmation_required'));
    expect(DB::table('journeys')->where('id', $fixture['journeyId'])->value('status'))->toBe('draft')
        ->and(DB::table('journey_versions')->where('journey_id', $fixture['journeyId'])->count())->toBe(0);

    $this->post("{$base}/publish", ['expected_revision' => 1, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'stale'));
    expect(DB::table('journey_versions')->where('journey_id', $fixture['journeyId'])->count())->toBe(0);

    $this->post("{$base}/publish", ['expected_revision' => 2, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'published')->where('notice.version', 1));
    expect(DB::table('journeys')->where('id', $fixture['journeyId'])->value('lifecycle_revision'))->toBe(1)
        ->and(DB::table('journey_versions')->where('journey_id', $fixture['journeyId'])->count())->toBe(1);

    $this->post("{$base}/lifecycle/activate", ['expected_revision' => 1, 'confirmed' => false])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.code', 'versioned_lifecycle_confirmation_required'));
    $this->post("{$base}/lifecycle/activate", ['expected_revision' => 1, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'active'));

    $this->post("{$base}/lifecycle/pause", ['expected_revision' => 1, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'stale'));
    expect(DB::table('journeys')->where('id', $fixture['journeyId'])->value('status'))->toBe('active')
        ->and(DB::table('journeys')->where('id', $fixture['journeyId'])->value('lifecycle_revision'))->toBe(2);

    $this->post("{$base}/lifecycle/pause", ['expected_revision' => 2, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'paused'));
    DB::table('journey_enrollments')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace,
        'journey_version_id' => DB::table('journey_versions')->where('journey_id', $fixture['journeyId'])->value('id'),
        'subject_id' => (string) Str::uuid(),
        'trigger_event_id' => 'pending-event',
        'enrollment_key' => hash('sha256', 'pending-enrollment'),
        'generation' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->post("{$base}/lifecycle/cancel", ['expected_revision' => 3, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'cancelled'));
    expect(DB::table('journey_enrollments')->where('trigger_event_id', 'pending-event')->value('status'))->toBe('cancelled');
    $this->post("{$base}/lifecycle/archive", ['expected_revision' => 4, 'confirmed' => true])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('notice.status', 'archived'));

    expect(DB::table('journeys')->where('id', $fixture['journeyId'])->value('lifecycle_revision'))->toBe(5)
        ->and(DB::table('journey_versions')->where('journey_id', $fixture['journeyId'])->value('version_number'))->toBe(1);
});

it('does not disclose another workspace journey list to an authenticated operator', function () {
    $this->withoutVite();
    $inside = task0052JourneyOperatorFixture();
    $foreignOrg = Organization::query()->create(['name' => 'Foreign Journey Org', 'slug' => 'foreign-journey-org']);
    $foreign = Workspace::query()->create([
        'organization_id' => $foreignOrg->getKey(), 'name' => 'Foreign Journey Workspace', 'slug' => 'foreign-journey-workspace',
    ]);
    DB::table('journeys')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $foreign->getKey(), 'name' => 'Private', 'status' => 'draft',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($inside['user'])
        ->get('/workspaces/'.$foreign->getKey().'/journeys')
        ->assertForbidden();
});

it('bounds loaded execution transition history to the newest eight events per row', function () {
    $this->withoutVite();
    $fixture = task0052JourneyOperatorFixture();
    $workspace = (string) $fixture['workspace']->getKey();
    $journey = $fixture['journeyId'];
    $version = (string) Str::uuid();
    $execution = (string) Str::uuid();
    $enrollment = (string) Str::uuid();
    $subject = (string) Str::uuid();
    $now = now();
    DB::table('journey_versions')->insert([
        'id' => $version, 'workspace_id' => $workspace, 'journey_id' => $journey,
        'version_number' => 1, 'graph' => '{}', 'definition_hash' => str_repeat('a', 64), 'status' => 'published',
        'reentry_policy' => 'never', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('journey_enrollments')->insert([
        'id' => $enrollment, 'workspace_id' => $workspace, 'journey_version_id' => $version,
        'subject_id' => $subject, 'trigger_event_id' => 'event-1', 'enrollment_key' => str_repeat('b', 64),
        'generation' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('journey_executions')->insert([
        'id' => $execution, 'workspace_id' => $workspace, 'journey_version_id' => $version,
        'subject_id' => $subject, 'enrollment_id' => $enrollment, 'execution_key' => str_repeat('c', 64),
        'status' => 'running', 'revision' => 20, 'created_at' => $now, 'updated_at' => $now,
    ]);
    for ($revision = 1; $revision <= 20; $revision++) {
        DB::table('journey_execution_transitions')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'execution_id' => $execution,
            'transition_revision' => $revision, 'event_type' => 'node_succeeded', 'node_id' => "node-{$revision}",
            'attempt' => null, 'metadata' => '{}', 'created_at' => $now->copy()->addSeconds($revision),
        ]);
    }

    $this->actingAs($fixture['user'])
        ->get("/workspaces/{$workspace}/journeys")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('timeline', 1)
            ->has('timeline.0.history', 8)
            ->where('timeline.0.history.0.node_id', 'node-13')
            ->where('timeline.0.history.7.node_id', 'node-20')
        );
});

it('refuses rolling back persisted lifecycle revision data even when no draft graph remains', function () {
    $fixture = task0052JourneyOperatorFixture();
    DB::table('journeys')->where('id', $fixture['journeyId'])->update([
        'draft_graph' => null,
        'draft_revision' => 0,
        'lifecycle_revision' => 2,
        'status' => 'active',
    ]);
    $migration = require base_path('database/migrations/2026_09_29_000002_add_journey_draft_and_lifecycle_revisions.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'persisted journey state exists');
});
