<?php

use App\Modules\AI\Application\BoundedAutonomyOfflineRedTeamIncidentReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRedTeamEvidenceSource;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamAudit;
use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyOfflineRedTeamMonitor;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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
    $facts = (object) ['value' => redteamAuditSigned($actor, [redteamAuditFinding()])];
    $service = redteamAuditService($facts, (object) ['allowed' => true]);

    $first = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($first['status'])->toBe('offline_redteam_review_ready')
        ->and($first['reason_code'])->toBe('human_incident_triage_required')
        ->and($first['production_incident_verified'])->toBeFalse()
        ->and($first['recovery_performed'])->toBeFalse();

    $facts->value = redteamAuditSigned($actor, [redteamAuditFinding('unresolved')]);
    $uncertain = $service->record($actor, 'attack-run', redteamAuditAt());
    expect($uncertain['status'])->toBe('held_offline')
        ->and($uncertain['reason_code'])->toBe('unresolved_redteam_safety_case');

    $facts->value = redteamAuditSigned($actor, [redteamAuditFinding()]);
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

    $facts = (object) ['value' => redteamAuditSigned($actor, [redteamAuditFinding()])];
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
        ->and($review['case_count'])->toBe(1)
        ->and($review['severity_counts']['critical'])->toBe(1)
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
    $facts = (object) ['value' => redteamAuditSigned($actor, [redteamAuditFinding()])];
    $recorder = redteamAuditService($facts, $permission);

    $recorder->record($actor, 'attack-run', redteamAuditAt());
    $facts->value = redteamAuditSigned($actor, [redteamAuditFinding('unresolved')]);
    $recorder->record($actor, 'attack-run', redteamAuditAt());
    $facts->value = redteamAuditSigned($actor, [redteamAuditFinding()]);
    $recorder->record($actor, 'attack-run', redteamAuditAt());

    $review = redteamAuditMonitor($permission)->inspect($actor, 'attack-run');
    expect($review['audit_sequence'])->toBe(3)
        ->and($review['status'])->toBe('held_offline')
        ->and($review['reason_code'])->toBe('prior_unresolved_redteam_safety_hold')
        ->and($review['case_count'])->toBe(1)
        ->and($review['recovery_performed'])->toBeFalse()
        ->and($review['production_incident_verified'])->toBeFalse();
});

it('fails closed on cross-tenant or cross-brand read, missing permission, and modified audit history', function () {
    $actor = redteamAuditScope();
    $permission = (object) ['allowed' => true];
    $facts = (object) ['value' => redteamAuditSigned($actor, [redteamAuditFinding()])];
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
