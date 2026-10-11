<?php

use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamHumanTriage;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function syntheticHumanTriageFixture(): array
{
    $org = (string) Str::uuid();
    $ws = (string) Str::uuid();
    DB::table('organizations')->insert([
        'id' => $org, 'name' => 'Synthetic incident triage fixture',
        'slug' => 'synthetic-triage-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $ws, 'organization_id' => $org,
        'name' => 'Synthetic incident workspace',
        'slug' => 'synthetic-triage-'.Str::random(12),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $requester = User::query()->create([
        'name' => 'Synthetic source reviewer',
        'email' => Str::random(12).'@example.test',
        'password' => Str::random(24),
    ]);
    $approver = User::query()->create([
        'name' => 'Human incident reviewer',
        'email' => Str::random(12).'@example.test',
        'password' => Str::random(24),
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($approver, $ws);
    $role = $roles->createRole($ws, 'incident_approver', 'Synthetic incident reviewer');
    $roles->assignRole($membership, $role);
    $roles->grantPermission($role, PermissionCatalog::AI_APPROVE);

    $scope = new TenantContext($org, $ws, null, (string) $requester->getKey());
    $event = app(AuditRecorder::class)->record(
        workspaceId: $ws,
        action: 'ai.autonomy.redteam.offline.review',
        evidence: [
            'run_id' => 'attack-run',
            'sequence' => 1,
            'previous_review_fingerprint' => null,
            'review_fingerprint' => str_repeat('a', 64),
            'source_manifest_sha256' => null,
            'recorded_status' => 'held_offline',
            'recorded_reason' => 'redteam_evidence_unavailable',
            'case_count' => 0,
            'severity_counts' => [
                'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0,
            ],
            'category_counts' => [
                'goal_hijack' => 0, 'tool_misuse' => 0,
                'self_approval' => 0, 'cross_tenant' => 0,
                'quota_race' => 0, 'emergency_stop' => 0,
                'provider_replay' => 0, 'metric_spoofing' => 0, 'exfiltration' => 0,
            ],
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'recovery_performed' => false,
        ],
        actorId: (string) $requester->getKey(),
        subjectType: 'offline_redteam_run',
        subjectId: 'attack-run',
        correlationId: 'attack-run',
    );

    return [$requester, $approver, $scope, $event->id, $role];
}

it('records only authenticated human synthetic triage and dedupes exact replay', function () {
    [$requester, $human, $scope, $sourceId] = syntheticHumanTriageFixture();
    $this->actingAs($human);
    $service = app(DatabaseBoundedAutonomyOfflineRedTeamHumanTriage::class);

    $first = $service->record($human, $scope, 'attack-run', $sourceId, 'investigation_requested');
    expect($first['status'])->toBe('offline_human_triage_note_recorded')
        ->and($first['replayed'])->toBeFalse()
        ->and($first['human_triage_audit_recorded'])->toBeTrue()
        ->and($first['production_incident_verified'])->toBeFalse()
        ->and($first['incident_resolved'])->toBeFalse()
        ->and($first['recovery_performed'])->toBeFalse()
        ->and($first['execution_authorized'])->toBeFalse()
        ->and($first['promotion_authorized'])->toBeFalse();

    $repeat = $service->record($human, $scope, 'attack-run', $sourceId, 'investigation_requested');
    expect($repeat['audit_event_id'])->toBe($first['audit_event_id'])
        ->and($repeat['replayed'])->toBeTrue()
        ->and(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.triage')->count())->toBe(1);

    $escalated = $service->record($human, $scope, 'attack-run', $sourceId, 'escalated');
    expect($escalated['replayed'])->toBeFalse()
        ->and($escalated['recovery_performed'])->toBeFalse()
        ->and(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.triage')->count())->toBe(2);

    $fact = json_decode(DB::table('audit_events')->where('id', $escalated['audit_event_id'])
        ->value('evidence'), true, flags: JSON_THROW_ON_ERROR);
    expect($fact['source_event_id'])->toBe($sourceId)
        ->and($fact['triage_decision'])->toBe('escalated')
        ->and($fact['incident_resolved'])->toBeFalse()
        ->and($fact['recovery_performed'])->toBeFalse()
        ->and($fact)->not->toHaveKey('payload_sha256')
        ->and($fact)->not->toHaveKey('raw_prompt');
});

it('fails closed on session spoof, self-approval, removed human role and unsafe outcomes', function () {
    [$requester, $human, $scope, $sourceId, $role] = syntheticHumanTriageFixture();
    $service = app(DatabaseBoundedAutonomyOfflineRedTeamHumanTriage::class);

    $this->actingAs($requester);
    expect(fn () => $service->record($human, $scope, 'attack-run', $sourceId, 'acknowledged'))
        ->toThrow(InvalidArgumentException::class);

    $this->actingAs($human);
    expect(fn () => $service->record($human, $scope, 'attack-run', $sourceId, 'auto_recover_provider'))
        ->toThrow(InvalidArgumentException::class);

    $self = new TenantContext($scope->organizationId, $scope->workspaceId, null, (string) $human->getKey());
    expect(fn () => $service->record($human, $self, 'attack-run', $sourceId, 'acknowledged'))
        ->toThrow(InvalidArgumentException::class);

    DB::table('workspace_role_permissions')->where('workspace_role_id', $role)
        ->where('permission', PermissionCatalog::AI_APPROVE)->delete();
    expect(fn () => $service->record($human, $scope, 'attack-run', $sourceId, 'acknowledged'))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.triage')->count())->toBe(0);
});

it('rejects foreign-organization, source-event and actor forgeries before any triage audit', function () {
    [$requester, $human, $scope, $sourceId] = syntheticHumanTriageFixture();
    $this->actingAs($human);
    $service = app(DatabaseBoundedAutonomyOfflineRedTeamHumanTriage::class);

    $foreign = new TenantContext((string) Str::uuid(), $scope->workspaceId, null, $scope->actorId);
    $other = new TenantContext($scope->organizationId, $scope->workspaceId, null, 'foreign-requester');
    foreach ([
        [$foreign, $sourceId],
        [$other, $sourceId],
        [$scope, (string) Str::uuid()],
    ] as [$actor, $eventId]) {
        expect(fn () => $service->record($human, $actor, 'attack-run', $eventId, 'escalated'))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)
        ->where('action', 'ai.autonomy.redteam.offline.triage')->count())->toBe(0);
});

it('refuses a stale source after a newer held review even when event timestamps collide', function () {
    [$requester, $human, $scope, $sourceId] = syntheticHumanTriageFixture();
    $this->actingAs($human);
    $audit = app(AuditRecorder::class);
    $new = $audit->record(
        workspaceId: $scope->workspaceId,
        action: 'ai.autonomy.redteam.offline.review',
        evidence: [
            'run_id' => 'attack-run',
            'sequence' => 2,
            'previous_review_fingerprint' => str_repeat('a', 64),
            'review_fingerprint' => str_repeat('b', 64),
            'recorded_status' => 'held_offline',
            'recorded_reason' => 'unresolved_redteam_safety_case',
            'case_count' => 0,
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'recovery_performed' => false,
        ],
        actorId: $scope->actorId,
        subjectType: 'offline_redteam_run',
        subjectId: 'attack-run',
        correlationId: 'attack-run',
    );
    $service = app(DatabaseBoundedAutonomyOfflineRedTeamHumanTriage::class);
    expect(fn () => $service->record($human, $scope, 'attack-run', $sourceId, 'acknowledged'))
        ->toThrow(InvalidArgumentException::class);

    $triage = $service->record($human, $scope, 'attack-run', $new->id, 'escalated');
    expect($triage['human_triage_audit_recorded'])->toBeTrue()
        ->and($triage['incident_resolved'])->toBeFalse()
        ->and($triage['recovery_performed'])->toBeFalse()
        ->and($triage['execution_authorized'])->toBeFalse();
});

it('rejects corrupt triage replay history instead of silently skipping malformed events', function () {
    [$requester, $human, $scope, $sourceId] = syntheticHumanTriageFixture();
    $this->actingAs($human);
    app(AuditRecorder::class)->record(
        workspaceId: $scope->workspaceId,
        action: 'ai.autonomy.redteam.offline.triage',
        evidence: [
            'run_id' => 'attack-run',
            'source_event_id' => $sourceId,
            'triage_decision' => 'escalated',
            'synthetic_evidence_only' => false,
        ],
        actorId: (string) $human->getKey(),
        subjectType: 'offline_redteam_run',
        subjectId: 'attack-run',
        correlationId: 'attack-run',
    );
    $service = app(DatabaseBoundedAutonomyOfflineRedTeamHumanTriage::class);
    expect(fn () => $service->record($human, $scope, 'attack-run', $sourceId, 'acknowledged'))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)
            ->where('action', 'ai.autonomy.redteam.offline.triage')->count())->toBe(1);
});
