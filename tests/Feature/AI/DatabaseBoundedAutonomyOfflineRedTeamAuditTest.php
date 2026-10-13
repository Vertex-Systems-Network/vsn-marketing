<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineRedTeamIncidentReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRedTeamEvidenceSource;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamAudit;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamMonitor;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

it('serves a bounded synthetic incident summary only to the authorized workspace operator', function () {
    $f = new AnalyticsFixture;
    $url = '/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run';
    $user = User::findOrFail($f->actor->actorId);
    $this->actingAs($user)->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get($url)->assertForbidden();

    app(WorkspaceRoleManager::class)->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $this->get($url)->assertOk()->assertJsonPath('status', 'held_offline')
        ->assertJsonPath('reason_code', 'redteam_audit_unavailable')
        ->assertJsonPath('production_incident_verified', false);

    $facts = (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage())];
    redteamAuditService($facts, (object) ['allowed' => true])->record($f->actor, 'attack-run', redteamAuditAt());
    $this->get($url)->assertOk()->assertJsonPath('case_count', 9)
        ->assertJsonPath('human_triage_required', true)
        ->assertJsonMissingPath('raw_prompt')
        ->assertJsonPath('recovery_performed', false)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/bad.run')
        ->assertUnprocessable()->assertExactJson([
            'status' => 'held_offline', 'reason_code' => 'audit_review_unavailable',
        ]);

    $other = new AnalyticsFixture;
    $this->get('/workspaces/'.$other->actor->workspaceId.'/autonomy/offline-incidents/attack-run')
        ->assertForbidden();
});

it('renders the operator review with current scoped counts and no recovery claim', function () {
    $this->withoutVite();
    $f = new AnalyticsFixture;
    $roles = app(WorkspaceRoleManager::class);
    $roles->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $url = '/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run/review';
    $this->actingAs(User::findOrFail($f->actor->actorId))->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ai/offline-incident')->where('review.status', 'held_offline')
        ->where('triage.status', 'not_recorded')->where('triage_available', false));
    redteamAuditService(
        (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage())],
        (object) ['allowed' => true],
    )->record($f->actor, 'attack-run', redteamAuditAt());
    $roles->grantPermission($f->role, PermissionCatalog::CAMPAIGN_APPROVE);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ai/offline-incident')->where('review.case_count', 9)
        ->where('review.production_incident_verified', false)
        ->where('review.recovery_performed', false)->where('triage_available', false));
    $reviewer = User::query()->create([
        'name' => 'Independent reviewer', 'email' => Str::random(12).'@example.test', 'password' => 'test-only',
    ]);
    $roles->assignRole($roles->addMember($reviewer, $f->actor->workspaceId), $f->role);
    $this->actingAs($reviewer)->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ai/offline-incident')->where('triage_available', true)
        ->where('triage.recovery_review_status', 'blocked_unverified_external_effect'));
});

it('records independent human triage without treating acknowledgement as recovery', function () {
    $this->withoutVite();
    $this->withoutMiddleware(ThrottleRequests::class);
    Queue::fake();
    $f = new AnalyticsFixture;
    $roles = app(WorkspaceRoleManager::class);
    $roles->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $source = redteamAuditService(
        (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage('unresolved'))],
        (object) ['allowed' => true],
    )->record($f->actor, 'attack-run', redteamAuditAt());
    $url = '/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run/triage';
    $payload = ['audit_event_id' => $source['audit_event_id'], 'decision' => 'investigating'];

    $this->actingAs(User::findOrFail($f->actor->actorId))
        ->withHeader('X-Brand-Id', $f->actor->brandId)
        ->postJson($url, $payload)->assertForbidden();
    $roles->grantPermission($f->role, PermissionCatalog::CAMPAIGN_APPROVE);
    $this->postJson($url, $payload)->assertUnprocessable()
        ->assertJsonPath('reason_code', 'triage_review_unavailable');

    $reviewer = User::query()->create([
        'name' => 'Independent reviewer', 'email' => Str::random(12).'@example.test', 'password' => 'test-only',
    ]);
    $membership = $roles->addMember($reviewer, $f->actor->workspaceId);
    $roles->assignRole($membership, $f->role);
    $this->actingAs($reviewer)->withHeader('X-Brand-Id', $f->actor->brandId)
        ->postJson($url, $payload)->assertOk()
        ->assertJsonPath('status', 'human_triage_recorded')
        ->assertJsonPath('incident_resolved', false)
        ->assertJsonPath('recovery_performed', false)
        ->assertJsonPath('recovery_review_status', 'blocked_unverified_external_effect')
        ->assertJsonPath('replayed', false);
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('replayed', true);
    $this->withHeader('X-Inertia', 'true')->post($url, $payload)
        ->assertRedirect('/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run/review');
    $this->withoutHeader('X-Inertia');
    $this->postJson($url, ['audit_event_id' => $source['audit_event_id'], 'decision' => 'escalated'])
        ->assertOk()->assertJsonPath('decision', 'escalated');
    $this->get('/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run/review')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ai/offline-incident')->where('triage.status', 'escalated')
        ->where('triage.current_review_triaged', true)
        ->where('triage.recovery_performed', false));
    $this->postJson($url, $payload)->assertUnprocessable();
    $this->postJson($url, ['audit_event_id' => (string) Str::uuid(), 'decision' => 'escalated'])
        ->assertUnprocessable();
    $this->postJson($url, ['audit_event_id' => $source['audit_event_id'], 'decision' => 'resolved'])
        ->assertUnprocessable();
    $this->get('/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run')
        ->assertOk()->assertJsonPath('status', 'held_offline')
        ->assertJsonPath('recovery_performed', false);
    $nextSource = redteamAuditService(
        (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage())],
        (object) ['allowed' => true],
    )->record($f->actor, 'attack-run', redteamAuditAt());
    $this->postJson($url, $payload)->assertUnprocessable()
        ->assertJsonPath('reason_code', 'triage_review_unavailable');
    $this->postJson($url, ['audit_event_id' => $nextSource['audit_event_id'], 'decision' => 'investigating'])
        ->assertUnprocessable()->assertJsonPath('reason_code', 'triage_review_unavailable');
    $this->postJson($url, ['audit_event_id' => $nextSource['audit_event_id'], 'decision' => 'escalated'])
        ->assertOk()->assertJsonPath('decision', 'escalated');
    $other = new AnalyticsFixture;
    $this->postJson('/workspaces/'.$other->actor->workspaceId.'/autonomy/offline-incidents/attack-run/triage', $payload)
        ->assertForbidden();
    expect(DB::table('audit_events')->where('action', 'ai.autonomy.redteam.offline.triage')->count())->toBe(3);
    Queue::assertNothingPushed();
    expect(DB::table('outbox_messages')->count())->toBe(0);
    $first = DB::table('audit_events')->where('action', 'ai.autonomy.redteam.offline.triage')->first();
    $tampered = json_decode($first->evidence, true, flags: JSON_THROW_ON_ERROR);
    $tampered['decision'] = 'escalated';
    DB::table('audit_events')->where('id', $first->id)->update([
        'evidence' => json_encode($tampered, JSON_THROW_ON_ERROR),
    ]);
    $this->postJson($url, ['audit_event_id' => $source['audit_event_id'], 'decision' => 'escalated'])
        ->assertUnprocessable()->assertJsonPath('reason_code', 'triage_review_unavailable');
});

it('rejects a triage chain whose referenced source audit event was replaced', function () {
    $f = new AnalyticsFixture;
    $roles = app(WorkspaceRoleManager::class);
    $roles->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $roles->grantPermission($f->role, PermissionCatalog::CAMPAIGN_APPROVE);
    $source = redteamAuditService(
        (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage())],
        (object) ['allowed' => true],
    )->record($f->actor, 'attack-run', redteamAuditAt());
    $reviewer = User::query()->create([
        'name' => 'Independent reviewer', 'email' => Str::random(12).'@example.test', 'password' => 'test-only',
    ]);
    $roles->assignRole($roles->addMember($reviewer, $f->actor->workspaceId), $f->role);
    $url = '/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run/triage';
    $this->actingAs($reviewer)->withHeader('X-Brand-Id', $f->actor->brandId)
        ->postJson($url, ['audit_event_id' => $source['audit_event_id'], 'decision' => 'investigating'])
        ->assertOk();

    $row = DB::table('audit_events')->where('action', 'ai.autonomy.redteam.offline.triage')->first();
    $fact = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
    $fact['audit_event_id'] = (string) Str::uuid();
    $fact['fingerprint'] = hash('sha256', json_encode([
        'attack-run', $fact['audit_event_id'], 'investigating', $reviewer->id, 1, null,
    ], JSON_THROW_ON_ERROR));
    DB::table('audit_events')->where('id', $row->id)->update([
        'evidence' => json_encode($fact, JSON_THROW_ON_ERROR),
    ]);
    $this->postJson($url, ['audit_event_id' => $source['audit_event_id'], 'decision' => 'escalated'])
        ->assertUnprocessable()->assertJsonPath('reason_code', 'triage_review_unavailable');
});

it('returns a generic held response when persisted incident JSON is corrupt', function () {
    $f = new AnalyticsFixture;
    app(WorkspaceRoleManager::class)->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $saved = redteamAuditService(
        (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage())],
        (object) ['allowed' => true],
    )->record($f->actor, 'attack-run', redteamAuditAt());
    DB::table('audit_events')->where('id', $saved['audit_event_id'])->update(['evidence' => 'null']);
    $this->actingAs(User::findOrFail($f->actor->actorId))
        ->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get('/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run')
        ->assertUnprocessable()->assertExactJson([
            'status' => 'held_offline', 'reason_code' => 'audit_review_unavailable',
        ])->assertHeader('Cache-Control', 'no-store, private');
});

it('rejects a persisted ready claim when a required attack category is absent', function () {
    $f = new AnalyticsFixture;
    app(WorkspaceRoleManager::class)->grantPermission($f->role, PermissionCatalog::CAMPAIGN_READ);
    $saved = redteamAuditService(
        (object) ['value' => redteamAuditSigned($f->actor, redteamAuditCoverage())],
        (object) ['allowed' => true],
    )->record($f->actor, 'attack-run', redteamAuditAt());
    $row = DB::table('audit_events')->where('id', $saved['audit_event_id'])->first();
    $fact = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
    $fact['category_counts']['exfiltration'] = 0;
    $fact['category_counts']['goal_hijack'] = 2;
    DB::table('audit_events')->where('id', $row->id)->update([
        'evidence' => json_encode($fact, JSON_THROW_ON_ERROR),
    ]);
    $this->actingAs(User::findOrFail($f->actor->actorId))
        ->withHeader('X-Brand-Id', $f->actor->brandId)
        ->get('/workspaces/'.$f->actor->workspaceId.'/autonomy/offline-incidents/attack-run')
        ->assertUnprocessable()->assertExactJson([
            'status' => 'held_offline', 'reason_code' => 'audit_review_unavailable',
        ]);
});

function redteamAuditScope(): TenantContext
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Synthetic offline incident audit',
        'slug' => 'redteam-audit-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspace, 'organization_id' => $org,
        'name' => 'Offline adversarial tests',
        'slug' => 'redteam-audit-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return new TenantContext($org, $workspace, null, 'human-analyst');
}

function redteamAuditAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-12T12:00:00+00:00');
}

function redteamAuditSigned(TenantContext $scope, array $findings): array
{
    $record = [
        'tenant' => $scope->toArray(),
        'run_id' => 'attack-run',
        'evidence_class' => 'synthetic_offline',
        'captured_at_unix' => redteamAuditAt()->getTimestamp(),
        'expires_at_unix' => redteamAuditAt()->getTimestamp() + 60,
        'complete' => true,
        'findings' => $findings,
    ];
    $record['source_manifest_sha256'] = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));

    return $record;
}

function redteamAuditFinding(string $outcome = 'blocked'): array
{
    return [
        'case_id' => 'hijack-case',
        'category' => 'goal_hijack',
        'severity' => 'critical',
        'outcome' => $outcome,
        'payload_sha256' => str_repeat('a', 64),
        'observed_at_unix' => redteamAuditAt()->getTimestamp() - 30,
    ];
}

function redteamAuditCoverage(string $firstOutcome = 'blocked'): array
{
    $categories = [
        'goal_hijack', 'tool_misuse', 'self_approval', 'cross_tenant',
        'quota_race', 'emergency_stop', 'provider_replay',
        'metric_spoofing', 'exfiltration',
    ];

    return array_map(static fn (string $category, int $index): array => array_replace(
        redteamAuditFinding($index === 0 ? $firstOutcome : 'blocked'),
        ['case_id' => 'case-'.($index + 1), 'category' => $category],
    ), $categories, array_keys($categories));
}

function redteamAuditService(object $facts, object $access): DatabaseBoundedAutonomyOfflineRedTeamAudit
{
    $source = new class($facts) implements BoundedAutonomyRedTeamEvidenceSource
    {
        public function __construct(private object $facts) {}

        public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
        {
            return $this->facts->value;
        }
    };
    $permission = new class($access) implements ExperimentAccess
    {
        public function __construct(private object $access) {}

        public function allows(TenantContext $actor, string $permission): bool
        {
            return $this->access->allowed && $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };

    return new DatabaseBoundedAutonomyOfflineRedTeamAudit(
        new BoundedAutonomyOfflineRedTeamIncidentReview($source),
        $permission,
        app(AuditRecorder::class),
    );
}

it('persists a redacted synthetic unknown-evidence audit once without claiming production incident or recovery', function () {
    $actor = redteamAuditScope();
    $access = (object) ['allowed' => true];
    $facts = (object) ['value' => null];
    $service = redteamAuditService($facts, $access);
    $first = $service->record($actor, 'attack-run', redteamAuditAt());

    expect($first['status'])->toBe('held_offline')
        ->and($first['reason_code'])->toBe('redteam_evidence_unavailable')
        ->and($first['offline_audit_recorded'])->toBeTrue()
        ->and($first['replayed'])->toBeFalse()
        ->and($first['production_incident_verified'])->toBeFalse()
        ->and($first['incident_resolved'])->toBeFalse()
        ->and($first['recovery_performed'])->toBeFalse()
        ->and($first['execution_authorized'])->toBeFalse()
        ->and($first['promotion_authorized'])->toBeFalse();

    $again = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($again['audit_event_id'])->toBe($first['audit_event_id'])
        ->and($again['replayed'])->toBeTrue()
        ->and(DB::table('audit_events')->where('workspace_id', $actor->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.review')->count())->toBe(1);

    $evidence = json_decode(DB::table('audit_events')->where('id', $first['audit_event_id'])
        ->value('evidence'), true, flags: JSON_THROW_ON_ERROR);
    expect($evidence['synthetic_evidence_only'])->toBeTrue()
        ->and($evidence['production_incident_verified'])->toBeFalse()
        ->and($evidence['recovery_performed'])->toBeFalse()
        ->and($evidence)->not->toHaveKey('raw_prompt')
        ->and($evidence)->not->toHaveKey('payload_sha256');

    $access->allowed = false;
    expect(fn () => $service->record($actor, 'attack-run', redteamAuditAt()))
        ->toThrow(InvalidArgumentException::class);
    $access->allowed = true;

    $imposter = new TenantContext($actor->organizationId, $actor->workspaceId, null, 'another-actor');
    expect(fn () => $service->record($imposter, 'attack-run', redteamAuditAt()))
        ->toThrow(InvalidArgumentException::class);

    $foreign = new TenantContext((string) Str::uuid(), $actor->workspaceId, null, $actor->actorId);
    expect(fn () => $service->record($foreign, 'attack-run', redteamAuditAt()))
        ->toThrow(InvalidArgumentException::class);
});

it('never replays an earlier synthetic pass over a later unresolved safety hold', function () {
    $actor = redteamAuditScope();
    $facts = (object) ['value' => redteamAuditSigned($actor, redteamAuditCoverage())];
    $service = redteamAuditService($facts, (object) ['allowed' => true]);

    $first = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($first['status'])->toBe('offline_redteam_review_ready')
        ->and($first['reason_code'])->toBe('human_incident_triage_required')
        ->and($first['production_incident_verified'])->toBeFalse()
        ->and($first['recovery_performed'])->toBeFalse();

    $facts->value = redteamAuditSigned($actor, redteamAuditCoverage('unresolved'));
    $uncertain = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($uncertain['status'])->toBe('held_offline')
        ->and($uncertain['reason_code'])->toBe('unresolved_redteam_safety_case');

    $facts->value = redteamAuditSigned($actor, redteamAuditCoverage());
    $later = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($later['status'])->toBe('held_offline')
        ->and($later['reason_code'])->toBe('prior_unresolved_redteam_safety_hold')
        ->and($later['recovery_performed'])->toBeFalse()
        ->and($later['incident_resolved'])->toBeFalse()
        ->and($later['execution_authorized'])->toBeFalse();

    $replay = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($replay['audit_event_id'])->toBe($later['audit_event_id'])
        ->and($replay['replayed'])->toBeTrue()
        ->and(DB::table('audit_events')->where('workspace_id', $actor->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.review')->count())->toBe(3);

    // Timestamps may collide under database precision; explicit per-run
    // sequences, not UUID sort order, define immutable event chronology.
    $history = DB::table('audit_events')->where('workspace_id', $actor->workspaceId)
        ->where('action', 'ai.autonomy.redteam.offline.review')
        ->get()
        ->map(static fn ($row): array => json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR))
        ->sortBy('sequence')->values();
    expect($history->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($history[0]['previous_review_fingerprint'])->toBeNull()
        ->and($history[1]['previous_review_fingerprint'])->toBe($history[0]['review_fingerprint'])
        ->and($history[2]['previous_review_fingerprint'])->toBe($history[1]['review_fingerprint'])
        ->and($history[2]['recorded_status'])->toBe('held_offline');
});

it('refuses spoofed synthetic provenance and raw payload fields before durable audit', function () {
    $actor = redteamAuditScope();
    $record = redteamAuditSigned($actor, [redteamAuditFinding()]);
    $record['findings'][0]['raw_secret'] = 'fake-private-string';
    $facts = (object) ['value' => $record];
    $service = redteamAuditService($facts, (object) ['allowed' => true]);

    expect(fn () => $service->record($actor, 'attack-run', redteamAuditAt()))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('audit_events')->where('workspace_id', $actor->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.review')->count())->toBe(0);
});

it('holds a source that claims complete coverage after observing only one attack class', function () {
    $actor = redteamAuditScope();
    $facts = (object) ['value' => redteamAuditSigned($actor, [redteamAuditFinding()])];
    $permission = (object) ['allowed' => true];

    $saved = redteamAuditService($facts, $permission)->record($actor, 'attack-run', redteamAuditAt());
    $review = redteamAuditMonitor($permission)->inspect($actor, 'attack-run');

    expect($saved['status'])->toBe('held_offline')
        ->and($saved['reason_code'])->toBe('redteam_coverage_incomplete')
        ->and($review['status'])->toBe('held_offline')
        ->and($review['case_count'])->toBe(0)
        ->and($review['production_incident_verified'])->toBeFalse()
        ->and($review['recovery_performed'])->toBeFalse();
});

function redteamAuditMonitor(object $access): DatabaseBoundedAutonomyOfflineRedTeamMonitor
{
    $permission = new class($access) implements ExperimentAccess
    {
        public function __construct(private object $access) {}

        public function allows(TenantContext $actor, string $permission): bool
        {
            return $this->access->allowed && $permission === PermissionCatalog::CAMPAIGN_READ;
        }
    };

    return new DatabaseBoundedAutonomyOfflineRedTeamMonitor($permission);
}

it('shows only a redacted synthetic operator summary with independent read authorization', function () {
    $actor = redteamAuditScope();
    $permission = (object) ['allowed' => true];
    $monitor = redteamAuditMonitor($permission);

    $empty = $monitor->inspect($actor, 'attack-run');
    expect($empty['status'])->toBe('held_offline')
        ->and($empty['reason_code'])->toBe('redteam_audit_unavailable')
        ->and($empty['audit_sequence'])->toBe(0)
        ->and($empty['audit_history_integrity_checked'])->toBeFalse()
        ->and($empty['production_incident_verified'])->toBeFalse();

    $facts = (object) ['value' => redteamAuditSigned($actor, redteamAuditCoverage())];
    $saved = redteamAuditService($facts, $permission)->record($actor, 'attack-run', redteamAuditAt());
    expect($saved['status'])->toBe('offline_redteam_review_ready');

    // Operator on the same tenant/brand may inspect even when a different
    // analyst produced the append, provided current read access is granted.
    $viewer = new TenantContext($actor->organizationId, $actor->workspaceId, null, 'other-analyst');
    $before = DB::table('audit_events')->where('workspace_id', $actor->workspaceId)->count();
    $review = $monitor->inspect($viewer, 'attack-run');
    $after = DB::table('audit_events')->where('workspace_id', $actor->workspaceId)->count();

    expect($review['status'])->toBe('offline_redteam_review_ready')
        ->and($review['audit_sequence'])->toBe(1)
        ->and($review['case_count'])->toBe(9)
        ->and($review['severity_counts']['critical'])->toBe(9)
        ->and($review['category_counts']['goal_hijack'])->toBe(1)
        ->and($review['audit_history_integrity_checked'])->toBeTrue()
        ->and($review['human_triage_required'])->toBeTrue()
        ->and($review['incident_resolved'])->toBeFalse()
        ->and($review['recovery_performed'])->toBeFalse()
        ->and($review['execution_authorized'])->toBeFalse()
        ->and($before)->toBe($after);

    $serialized = json_encode($review, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('hijack-case')
        ->and($serialized)->not->toContain('payload_sha256')
        ->and($serialized)->not->toContain($actor->actorId);

    $permission->allowed = false;
    expect(fn () => $monitor->inspect($viewer, 'attack-run'))->toThrow(InvalidArgumentException::class);
});

it('reports newer unresolved holds without replaying a historical passing synthetic review', function () {
    $actor = redteamAuditScope();
    $permission = (object) ['allowed' => true];
    $facts = (object) ['value' => redteamAuditSigned($actor, redteamAuditCoverage())];
    $recorder = redteamAuditService($facts, $permission);

    $recorder->record($actor, 'attack-run', redteamAuditAt());
    $facts->value = redteamAuditSigned($actor, redteamAuditCoverage('unresolved'));
    $recorder->record($actor, 'attack-run', redteamAuditAt());
    $facts->value = redteamAuditSigned($actor, redteamAuditCoverage());
    $recorder->record($actor, 'attack-run', redteamAuditAt());

    $review = redteamAuditMonitor($permission)->inspect($actor, 'attack-run');
    expect($review['audit_sequence'])->toBe(3)
        ->and($review['status'])->toBe('held_offline')
        ->and($review['reason_code'])->toBe('prior_unresolved_redteam_safety_hold')
        ->and($review['case_count'])->toBe(9)
        ->and($review['recovery_performed'])->toBeFalse()
        ->and($review['production_incident_verified'])->toBeFalse();
});

it('fails closed on cross-tenant or cross-brand read, missing permission, and modified audit history', function () {
    $actor = redteamAuditScope();
    $permission = (object) ['allowed' => true];
    $facts = (object) ['value' => redteamAuditSigned($actor, redteamAuditCoverage())];
    $first = redteamAuditService($facts, $permission)->record($actor, 'attack-run', redteamAuditAt());
    $monitor = redteamAuditMonitor($permission);

    $foreignOrg = new TenantContext((string) Str::uuid(), $actor->workspaceId, null, $actor->actorId);
    $foreignBrand = new TenantContext($actor->organizationId, $actor->workspaceId, (string) Str::uuid(), $actor->actorId);

    expect(fn () => $monitor->inspect($foreignOrg, 'attack-run'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $monitor->inspect($foreignBrand, 'attack-run'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $monitor->inspect($actor, 'bad run'))->toThrow(InvalidArgumentException::class);

    // A forged resolution or non-synthetic authority in a persisted row
    // cannot be presented to an operator as legitimate incident status.
    $record = json_decode(DB::table('audit_events')->where('id', $first['audit_event_id'])
        ->value('evidence'), true, flags: JSON_THROW_ON_ERROR);
    $record['production_incident_verified'] = true;
    DB::table('audit_events')->where('id', $first['audit_event_id'])
        ->update(['evidence' => json_encode($record, JSON_THROW_ON_ERROR)]);

    expect(fn () => $monitor->inspect($actor, 'attack-run'))->toThrow(InvalidArgumentException::class);
});
