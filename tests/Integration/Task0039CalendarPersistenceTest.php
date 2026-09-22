<?php

use App\Modules\Publishing\Domain\Scheduling\CampaignSchedule;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleMutation;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleOccurrenceOutcome;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleRuleSet;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleMutationRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleOutcomeRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRuleRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) === false) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0039 PostgreSQL persistence tests.');
    }
});

/**
 * @param  array<string, mixed>|null  $intendedExecution
 * @return array{workspaceId: string, campaignId: string, snapshotId: string, approvalId: string, targetHash: string}
 */
function task0039PersistenceFixture(string $suffix, ?array $intendedExecution = null): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $campaignId = (string) Str::uuid();
    $snapshotId = (string) Str::uuid();
    $approvalId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    $contentVersionId = (string) Str::uuid();
    $targetHash = hash('sha256', 'task0039-'.$suffix);
    $now = new DateTimeImmutable('2026-07-15T10:00:00+00:00');

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0039 '.$suffix,
        'slug' => 'task0039-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0039 '.$suffix,
        'slug' => 'task0039-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0039 content '.$suffix,
        'lifecycle' => 'active',
        'created_by_actor_id' => 'task0039-author',
        'audit_provenance' => json_encode(['source' => 'task0039-integration'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => null,
    ]);
    DB::table('content_versions')->insert([
        'id' => $contentVersionId,
        'workspace_id' => $workspaceId,
        'document_id' => $documentId,
        'parent_version_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'status' => 'published',
        'canonical_tree' => json_encode(['schema_version' => 1, 'root' => []], JSON_THROW_ON_ERROR),
        'audit_provenance' => json_encode(['source' => 'task0039-integration'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'content-'.$suffix,
        'created_by_actor_id' => 'task0039-author',
        'created_at' => $now,
    ]);
    DB::table('campaigns')->insert([
        'id' => $campaignId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0039 campaign '.$suffix,
        'status' => 'scheduled_intent',
        'state_version' => 5,
        'idempotency_key' => 'campaign-'.$suffix,
        'created_by_actor_id' => 'task0039-author',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('campaign_snapshots')->insert([
        'id' => $snapshotId,
        'workspace_id' => $workspaceId,
        'campaign_id' => $campaignId,
        'parent_snapshot_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'content_version_id' => $contentVersionId,
        'template_version_id' => null,
        'component_version_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'asset_reference_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'capability_evidence_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'brand_reference' => json_encode([], JSON_THROW_ON_ERROR),
        'intended_execution' => json_encode($intendedExecution ?? [
            'mode' => 'fixed_instant',
            'timezone' => 'America/New_York',
            'at' => '2026-07-15T09:30:00',
        ], JSON_THROW_ON_ERROR),
        'target_set_hash' => $targetHash,
        'snapshot_hash' => hash('sha256', 'snapshot-'.$suffix),
        'idempotency_key' => 'snapshot-'.$suffix,
        'created_by_actor_id' => 'task0039-author',
        'created_at' => $now,
    ]);
    DB::table('campaign_approval_decisions')->insert([
        'id' => $approvalId,
        'workspace_id' => $workspaceId,
        'campaign_id' => $campaignId,
        'snapshot_id' => $snapshotId,
        'target_set_hash' => $targetHash,
        'outcome' => 'approved',
        'actor_id' => 'task0039-approver',
        'actor_role' => 'campaign-approver',
        'reason' => 'Approved for schedule persistence test.',
        'capability_evidence_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'supersedes_decision_id' => null,
        'expires_at' => new DateTimeImmutable('2026-07-15T14:00:00+00:00'),
        'idempotency_key' => 'approval-'.$suffix,
        'occurred_at' => new DateTimeImmutable('2026-07-15T10:05:00+00:00'),
    ]);

    return compact('workspaceId', 'campaignId', 'snapshotId', 'approvalId', 'targetHash');
}

it('persists replay-safe immutable fixed-instant schedules and preserves rows across re-entrant migration', function () {
    $fixture = task0039PersistenceFixture('fixed');
    $repository = app(DatabaseCampaignScheduleRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-fixed',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );

    $stored = $repository->create($schedule);
    $replayed = $repository->create($schedule);

    expect($stored->scheduleHash)->toBe($schedule->scheduleHash)
        ->and($replayed->id)->toBe($schedule->id)
        ->and(DB::table('campaign_schedules')->count())->toBe(1);

    $migration = require database_path('migrations/2026_09_22_000001_create_campaign_schedule_foundation_tables.php');
    $migration->up();

    expect(DB::table('campaign_schedules')->count())->toBe(1);

    expect(fn () => DB::table('campaign_schedules')->where('id', $schedule->id)->update([
        'timezone_id' => 'UTC',
    ]))->toThrow(QueryException::class);
});

it('fails closed when a schedule identity is read from another workspace', function () {
    $fixture = task0039PersistenceFixture('scope-a');
    $other = task0039PersistenceFixture('scope-b');
    $repository = app(DatabaseCampaignScheduleRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-scope-a',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $repository->create($schedule);

    expect(fn () => $repository->find($other['workspaceId'], $schedule->id))
        ->toThrow(AuthorizationException::class, 'Campaign schedule reference access denied.');
});

it('persists immutable queue-rule bindings and keeps pinned occurrences stable across later rule versions', function () {
    $ruleId = (string) Str::uuid();
    $fixture = task0039PersistenceFixture('queue', [
        'mode' => 'queue_next_slot',
        'rule_set_id' => $ruleId,
        'channel' => 'email',
    ]);
    $rules = app(DatabaseCampaignScheduleRuleRepository::class);
    $schedules = app(DatabaseCampaignScheduleRepository::class);

    $ruleV1 = $rules->create(CampaignScheduleRuleSet::create(
        id: $ruleId,
        workspaceId: $fixture['workspaceId'],
        parentRuleSetId: null,
        channel: 'email',
        versionNumber: 1,
        timezoneId: 'America/New_York',
        slots: [
            ['weekday' => 3, 'local_time' => '09:30:00'],
        ],
        idempotencyKey: 'queue-rule-v1',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:06:00+00:00'),
    ));

    $schedule = CampaignSchedule::queueNextSlot(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        channel: 'email',
        ruleSet: $ruleV1,
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'queue-schedule-v1',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $stored = $schedules->create($schedule);

    $ruleV2 = $rules->create(CampaignScheduleRuleSet::create(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        parentRuleSetId: $ruleV1->id,
        channel: 'email',
        versionNumber: 2,
        timezoneId: 'UTC',
        slots: [
            ['weekday' => 3, 'local_time' => '20:00:00'],
        ],
        idempotencyKey: 'queue-rule-v2',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:11:00+00:00'),
    ));

    $reloaded = $schedules->find($fixture['workspaceId'], $stored->id);

    expect($ruleV2->versionNumber)->toBe(2)
        ->and($reloaded?->ruleSetId)->toBe($ruleV1->id)
        ->and($reloaded?->ruleVersion)->toBe(1)
        ->and($reloaded?->ruleHash)->toBe($ruleV1->ruleHash)
        ->and($reloaded?->timezoneId)->toBe('America/New_York')
        ->and($reloaded?->resolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and(DB::table('campaign_schedule_rule_sets')->count())->toBe(2)
        ->and(DB::table('campaign_queue_schedule_bindings')->count())->toBe(1);

    $migration = require database_path('migrations/2026_09_22_000002_create_campaign_queue_rule_foundation_tables.php');
    $migration->up();

    expect(DB::table('campaign_schedule_rule_sets')->count())->toBe(2)
        ->and(DB::table('campaign_queue_schedule_bindings')->count())->toBe(1);

    expect(fn () => DB::table('campaign_schedule_rule_sets')->where('id', $ruleV1->id)->update([
        'timezone_id' => 'UTC',
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('campaign_queue_schedule_bindings')->where('schedule_id', $stored->id)->update([
        'rule_version' => 2,
    ]))->toThrow(QueryException::class);
});

it('fails closed when reading a schedule rule from another workspace', function () {
    $owner = task0039PersistenceFixture('rule-scope-owner');
    $other = task0039PersistenceFixture('rule-scope-other');
    $rules = app(DatabaseCampaignScheduleRuleRepository::class);

    $rule = $rules->create(CampaignScheduleRuleSet::create(
        id: (string) Str::uuid(),
        workspaceId: $owner['workspaceId'],
        parentRuleSetId: null,
        channel: 'email',
        versionNumber: 1,
        timezoneId: 'UTC',
        slots: [
            ['weekday' => 3, 'local_time' => '12:00:00'],
        ],
        idempotencyKey: 'rule-scope-owner-v1',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:06:00+00:00'),
    ));

    expect(fn () => $rules->find($other['workspaceId'], $rule->id))
        ->toThrow(AuthorizationException::class, 'Campaign schedule rule reference access denied.');
});

it('persists append-only cancellation history with replay safety and terminality', function () {
    $fixture = task0039PersistenceFixture('cancel-history');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $mutations = app(DatabaseCampaignScheduleMutationRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-cancel-history',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $schedules->create($schedule);

    $mutation = CampaignScheduleMutation::cancelled(
        id: (string) Str::uuid(),
        previous: $schedule,
        actorId: 'task0039-author',
        reason: 'Operator cancelled before execution.',
        idempotencyKey: 'mutation-cancel-history',
        occurredAt: new DateTimeImmutable('2026-07-15T10:20:00+00:00'),
    );

    $stored = $mutations->create($mutation);
    $replayed = $mutations->create($mutation);

    expect($stored->mutationHash)->toBe($mutation->mutationHash)
        ->and($replayed->id)->toBe($mutation->id)
        ->and(DB::table('campaign_schedule_mutations')->count())->toBe(1)
        ->and(DB::table('campaign_schedules')->where('id', $schedule->id)->value('resolved_at_utc'))
        ->not->toBeNull();

    $second = CampaignScheduleMutation::cancelled(
        id: (string) Str::uuid(),
        previous: $schedule,
        actorId: 'task0039-author',
        reason: 'Conflicting second cancellation.',
        idempotencyKey: 'mutation-cancel-history-second',
        occurredAt: new DateTimeImmutable('2026-07-15T10:21:00+00:00'),
    );

    expect(fn () => $mutations->create($second))->toThrow(
        InvalidArgumentException::class,
        'already has terminal reschedule/cancellation history',
    );

    $migration = require database_path('migrations/2026_09_22_000003_create_campaign_schedule_mutation_tables.php');
    $migration->up();

    expect(DB::table('campaign_schedule_mutations')->count())->toBe(1);

    expect(fn () => DB::table('campaign_schedule_mutations')->where('id', $mutation->id)->update([
        'reason' => 'mutated history',
    ]))->toThrow(QueryException::class);
});

it('persists reschedule lineage without rewriting the previous immutable schedule', function () {
    $fixture = task0039PersistenceFixture('reschedule-history');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $mutations = app(DatabaseCampaignScheduleMutationRepository::class);

    $previous = $schedules->create(CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-reschedule-history-old',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    ));

    $replacement = $schedules->create(CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T10:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        idempotencyKey: 'schedule-reschedule-history-new',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:20:00+00:00'),
    ));

    $mutation = $mutations->create(CampaignScheduleMutation::rescheduled(
        id: (string) Str::uuid(),
        previous: $previous,
        replacement: $replacement,
        actorId: 'task0039-author',
        reason: 'Move to the approved replacement instant.',
        idempotencyKey: 'mutation-reschedule-history',
        occurredAt: new DateTimeImmutable('2026-07-15T10:20:00+00:00'),
    ));

    $oldRow = DB::table('campaign_schedules')->where('id', $previous->id)->first();

    expect($mutation->previousScheduleId)->toBe($previous->id)
        ->and($mutation->replacementScheduleId)->toBe($replacement->id)
        ->and($mutation->previousResolvedAtUtc->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T13:30:00+00:00')
        ->and($mutation->replacementResolvedAtUtc?->format('Y-m-d\\TH:i:sP'))->toBe('2026-07-15T14:30:00+00:00')
        ->and($oldRow?->schedule_hash)->toBe($previous->scheduleHash)
        ->and(DB::table('campaign_schedules')->count())->toBe(2)
        ->and(DB::table('campaign_schedule_mutations')->count())->toBe(1);
});

it('fails closed when schedule mutation history is read from another workspace', function () {
    $owner = task0039PersistenceFixture('mutation-scope-owner');
    $other = task0039PersistenceFixture('mutation-scope-other');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $mutations = app(DatabaseCampaignScheduleMutationRepository::class);

    $schedule = $schedules->create(CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $owner['workspaceId'],
        campaignId: $owner['campaignId'],
        snapshotId: $owner['snapshotId'],
        approvalId: $owner['approvalId'],
        targetSetHash: $owner['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'mutation-scope-schedule',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    ));

    $mutation = $mutations->create(CampaignScheduleMutation::cancelled(
        id: (string) Str::uuid(),
        previous: $schedule,
        actorId: 'task0039-author',
        reason: 'Cancel scoped schedule.',
        idempotencyKey: 'mutation-scope-cancel',
        occurredAt: new DateTimeImmutable('2026-07-15T10:20:00+00:00'),
    ));

    expect(fn () => $mutations->find($other['workspaceId'], $mutation->id))
        ->toThrow(AuthorizationException::class, 'Campaign schedule mutation reference access denied.');

    expect(fn () => $mutations->history($other['workspaceId'], $owner['campaignId']))
        ->toThrow(AuthorizationException::class, 'Campaign schedule mutation reference access denied.');
});

it('rejects reschedule lineage that keeps the same canonical UTC occurrence', function () {
    $fixture = task0039PersistenceFixture('same-instant');
    $previous = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'same-instant-old',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $replacement = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'UTC',
        localScheduledAt: '2026-07-15T13:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'same-instant-new',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:20:00+00:00'),
    );

    expect(fn () => CampaignScheduleMutation::rescheduled(
        id: (string) Str::uuid(),
        previous: $previous,
        replacement: $replacement,
        actorId: 'task0039-author',
        reason: 'Attempt no-op reschedule.',
        idempotencyKey: 'same-instant-mutation',
        occurredAt: new DateTimeImmutable('2026-07-15T10:20:00+00:00'),
    ))->toThrow(
        InvalidArgumentException::class,
        'must change the resolved UTC instant',
    );
});

it('persists immutable missed occurrence outcomes with replay safety and re-entrant migration', function () {
    $fixture = task0039PersistenceFixture('missed-outcome');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $outcomes = app(DatabaseCampaignScheduleOutcomeRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-missed-outcome',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $schedules->create($schedule);

    $outcome = CampaignScheduleOccurrenceOutcome::executionDeadlineMissed(
        id: (string) Str::uuid(),
        schedule: $schedule,
        evaluatedDecisionId: $fixture['approvalId'],
        recordedByActorId: 'task0039-author',
        idempotencyKey: 'outcome-missed-deadline',
        observedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    $stored = $outcomes->create($outcome);
    $replayed = $outcomes->create($outcome);

    expect($stored->outcomeHash)->toBe($outcome->outcomeHash)
        ->and($replayed->id)->toBe($outcome->id)
        ->and($stored->state->value)->toBe('missed_needs_reschedule')
        ->and($stored->missedReason->value)->toBe('execution_deadline_missed')
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(1);

    $migration = require database_path(
        'migrations/2026_09_22_000004_create_campaign_schedule_occurrence_outcome_tables.php',
    );
    $migration->up();

    expect(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(1);

    expect(fn () => DB::table('campaign_schedule_occurrence_outcomes')
        ->where('id', $outcome->id)
        ->update(['missed_reason' => 'approval_invalid']))
        ->toThrow(QueryException::class);
});

it('fails closed when a schedule occurrence outcome is read from another workspace', function () {
    $owner = task0039PersistenceFixture('outcome-scope-owner');
    $other = task0039PersistenceFixture('outcome-scope-other');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $outcomes = app(DatabaseCampaignScheduleOutcomeRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $owner['workspaceId'],
        campaignId: $owner['campaignId'],
        snapshotId: $owner['snapshotId'],
        approvalId: $owner['approvalId'],
        targetSetHash: $owner['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-outcome-scope-owner',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $schedules->create($schedule);

    $outcome = CampaignScheduleOccurrenceOutcome::executionDeadlineMissed(
        id: (string) Str::uuid(),
        schedule: $schedule,
        evaluatedDecisionId: $owner['approvalId'],
        recordedByActorId: 'task0039-author',
        idempotencyKey: 'outcome-scope-owner',
        observedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );
    $outcomes->create($outcome);

    expect(fn () => $outcomes->find($other['workspaceId'], $outcome->id))
        ->toThrow(AuthorizationException::class, 'Campaign schedule outcome reference access denied.');
});

it('rejects missed occurrence history after terminal reschedule or cancellation history', function () {
    $fixture = task0039PersistenceFixture('outcome-terminal-conflict');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $mutations = app(DatabaseCampaignScheduleMutationRepository::class);
    $outcomes = app(DatabaseCampaignScheduleOutcomeRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-outcome-terminal-conflict',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $schedules->create($schedule);

    $mutations->create(CampaignScheduleMutation::cancelled(
        id: (string) Str::uuid(),
        previous: $schedule,
        actorId: 'task0039-author',
        reason: 'Cancel before occurrence.',
        idempotencyKey: 'mutation-outcome-terminal-conflict',
        occurredAt: new DateTimeImmutable('2026-07-15T12:00:00+00:00'),
    ));

    $outcome = CampaignScheduleOccurrenceOutcome::executionDeadlineMissed(
        id: (string) Str::uuid(),
        schedule: $schedule,
        evaluatedDecisionId: $fixture['approvalId'],
        recordedByActorId: 'task0039-author',
        idempotencyKey: 'outcome-terminal-conflict',
        observedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );

    expect(fn () => $outcomes->create($outcome))->toThrow(
        InvalidArgumentException::class,
        'cannot be recorded after terminal reschedule/cancellation history',
    );

    expect(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(0);
});

it('rejects backdated reschedule or cancellation after a terminal occurrence outcome', function () {
    $fixture = task0039PersistenceFixture('mutation-after-outcome');
    $schedules = app(DatabaseCampaignScheduleRepository::class);
    $outcomes = app(DatabaseCampaignScheduleOutcomeRepository::class);
    $mutations = app(DatabaseCampaignScheduleMutationRepository::class);
    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $fixture['workspaceId'],
        campaignId: $fixture['campaignId'],
        snapshotId: $fixture['snapshotId'],
        approvalId: $fixture['approvalId'],
        targetSetHash: $fixture['targetHash'],
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'schedule-mutation-after-outcome',
        createdByActorId: 'task0039-author',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    $schedules->create($schedule);

    $outcomes->create(CampaignScheduleOccurrenceOutcome::executionDeadlineMissed(
        id: (string) Str::uuid(),
        schedule: $schedule,
        evaluatedDecisionId: $fixture['approvalId'],
        recordedByActorId: 'task0039-author',
        idempotencyKey: 'outcome-before-backdated-mutation',
        observedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    ));

    $backdatedMutation = CampaignScheduleMutation::cancelled(
        id: (string) Str::uuid(),
        previous: $schedule,
        actorId: 'task0039-author',
        reason: 'Backdated cancellation must not double-terminate the occurrence.',
        idempotencyKey: 'mutation-after-terminal-outcome',
        occurredAt: new DateTimeImmutable('2026-07-15T12:00:00+00:00'),
    );

    expect(fn () => $mutations->create($backdatedMutation))->toThrow(
        InvalidArgumentException::class,
        'after a terminal occurrence outcome',
    );

    expect(DB::table('campaign_schedule_mutations')->count())->toBe(0)
        ->and(DB::table('campaign_schedule_occurrence_outcomes')->count())->toBe(1);
});
