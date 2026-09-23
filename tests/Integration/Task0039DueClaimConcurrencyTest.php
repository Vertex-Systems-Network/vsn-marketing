<?php

use App\Modules\Publishing\Domain\Scheduling\CampaignSchedule;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (!filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0039 due-claim concurrency certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0039 due-claim contention certification requires PostgreSQL.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** @return array{workspace_id: string, schedule_id: string, approval_id: string} */
function task0039DueClaimConcurrencyFixture(): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $campaignId = (string) Str::uuid();
    $snapshotId = (string) Str::uuid();
    $approvalId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    $contentVersionId = (string) Str::uuid();
    $targetHash = hash('sha256', 'task0039-due-claim-concurrency');
    $createdAt = new DateTimeImmutable('2026-07-15T10:00:00+00:00');

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0039 Due Claim Concurrency',
        'slug' => 'task0039-due-claim-concurrency',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0039 Due Claim Workspace',
        'slug' => 'task0039-due-claim-workspace',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0039 Due Claim Content',
        'lifecycle' => 'active',
        'created_by_actor_id' => 'task0039-concurrency',
        'audit_provenance' => json_encode(['source' => 'task0039-due-claim'], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
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
        'audit_provenance' => json_encode(['source' => 'task0039-due-claim'], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'task0039-due-claim-content',
        'created_by_actor_id' => 'task0039-concurrency',
        'created_at' => $createdAt,
    ]);
    DB::table('campaigns')->insert([
        'id' => $campaignId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0039 Due Claim Campaign',
        'status' => 'scheduled_intent',
        'state_version' => 5,
        'idempotency_key' => 'task0039-due-claim-campaign',
        'created_by_actor_id' => 'task0039-concurrency',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
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
        'intended_execution' => json_encode([
            'mode' => 'fixed_instant',
            'timezone' => 'America/New_York',
            'at' => '2026-07-15T09:30:00',
        ], JSON_THROW_ON_ERROR),
        'target_set_hash' => $targetHash,
        'snapshot_hash' => hash('sha256', 'task0039-due-claim-snapshot'),
        'idempotency_key' => 'task0039-due-claim-snapshot',
        'created_by_actor_id' => 'task0039-concurrency',
        'created_at' => $createdAt,
    ]);
    DB::table('campaign_approval_decisions')->insert([
        'id' => $approvalId,
        'workspace_id' => $workspaceId,
        'campaign_id' => $campaignId,
        'snapshot_id' => $snapshotId,
        'target_set_hash' => $targetHash,
        'outcome' => 'approved',
        'actor_id' => 'task0039-concurrency-approver',
        'actor_role' => 'campaign-approver',
        'reason' => 'Approved for due-claim contention certification.',
        'capability_evidence_ids' => json_encode([], JSON_THROW_ON_ERROR),
        'supersedes_decision_id' => null,
        'expires_at' => new DateTimeImmutable('2026-07-15T14:00:00+00:00'),
        'idempotency_key' => 'task0039-due-claim-approval',
        'occurred_at' => new DateTimeImmutable('2026-07-15T10:05:00+00:00'),
    ]);

    $schedule = CampaignSchedule::fixedInstant(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        campaignId: $campaignId,
        snapshotId: $snapshotId,
        approvalId: $approvalId,
        targetSetHash: $targetHash,
        timezoneId: 'America/New_York',
        localScheduledAt: '2026-07-15T09:30:00',
        resolvedAtUtc: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
        idempotencyKey: 'task0039-due-claim-schedule',
        createdByActorId: 'task0039-concurrency',
        createdAt: new DateTimeImmutable('2026-07-15T10:10:00+00:00'),
    );
    app(DatabaseCampaignScheduleRepository::class)->create($schedule);

    return [
        'workspace_id' => $workspaceId,
        'schedule_id' => $schedule->id,
        'approval_id' => $approvalId,
    ];
}

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<array<string, mixed>>
 */
function task0039RunConcurrentWorkers(string $script, array $payloads): array
{
    $path = tempnam(sys_get_temp_dir(), 'vsn-task0039-due-claim-');
    if ($path === false || file_put_contents($path, $script) === false) {
        throw new RuntimeException('Unable to create TASK-0039 due-claim worker script.');
    }

    $processes = [];
    $resultPaths = [];

    try {
        foreach ($payloads as $payload) {
            $resultPath = sys_get_temp_dir().'/vsn-task0039-result-'.bin2hex(random_bytes(16)).'.json';
            $payload['_result_path'] = $resultPath;
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, $path, $encoded], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
            $resultPaths[] = $resultPath;
        }

        $results = [];
        foreach ($processes as $index => $process) {
            $exitCode = $process->wait();
            if ($exitCode !== 0) {
                throw new RuntimeException(trim($process->getErrorOutput().' '.$process->getOutput()));
            }

            $resultJson = is_file($resultPaths[$index])
                ? trim((string) file_get_contents($resultPaths[$index]))
                : '';
            if ($resultJson === '') {
                throw new RuntimeException(
                    'TASK-0039 concurrency worker emitted no result file. stdout='
                    .trim($process->getOutput()).' stderr='.trim($process->getErrorOutput())
                );
            }

            $decoded = json_decode($resultJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('TASK-0039 concurrency worker returned invalid JSON.');
            }

            $results[] = $decoded;
        }

        return $results;
    } finally {
        @unlink($path);
        foreach ($resultPaths as $resultPath) {
            @unlink($resultPath);
        }
    }
}

function task0039ClaimWorkerScript(): string
{
    return <<<'PHP'
<?php
$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result = Illuminate\Support\Facades\DB::transaction(function () use ($payload): array {
    $schedules = app(App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository::class);
    $executions = app(App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleExecutionRepository::class);
    $schedule = $schedules->lockForUpdate($payload['workspace_id'], $payload['schedule_id']);
    if ($schedule === null) {
        throw new RuntimeException('Schedule disappeared during claim contention.');
    }

    $claim = $executions->findClaimBySchedule($payload['workspace_id'], $payload['schedule_id'], true);
    if ($claim === null) {
        $claim = $executions->createClaim(
            App\Modules\Publishing\Domain\Scheduling\CampaignScheduleDueClaim::firstLease(
                id: (string) Illuminate\Support\Str::uuid(),
                schedule: $schedule,
                evaluatedApprovalId: $payload['approval_id'],
                leaseOwner: 'task0039-concurrency-worker',
                leaseToken: 'task0039-concurrency-token',
                claimedAt: new DateTimeImmutable('2026-07-15T13:30:00+00:00'),
                leaseExpiresAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
            ),
        );
    }

    return ['claim_id' => $claim->id];
});

$resultJson = json_encode($result, JSON_THROW_ON_ERROR);
if (!isset($payload['_result_path']) || file_put_contents($payload['_result_path'], $resultJson, LOCK_EX) === false) {
    throw new RuntimeException('Unable to persist TASK-0039 claim worker result.');
}
PHP;
}

function task0039EmitWorkerScript(): string
{
    return <<<'PHP'
<?php
$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$intent = app(App\Modules\Publishing\Application\Scheduling\CampaignScheduleDueClaimService::class)
    ->emitExecutionIntent(
        workspaceId: $payload['workspace_id'],
        scheduleId: $payload['schedule_id'],
        leaseOwner: 'task0039-concurrency-worker',
        leaseToken: 'task0039-concurrency-token',
        at: new DateTimeImmutable('2026-07-15T13:30:10+00:00'),
    );

$resultJson = json_encode(['intent_id' => $intent->id, 'outbox_id' => $intent->outboxId], JSON_THROW_ON_ERROR);
if (!isset($payload['_result_path']) || file_put_contents($payload['_result_path'], $resultJson, LOCK_EX) === false) {
    throw new RuntimeException('Unable to persist TASK-0039 emit worker result.');
}
PHP;
}

it('serializes duplicate PostgreSQL claim and emit workers to one intent and one outbox handoff', function () {
    $fixture = task0039DueClaimConcurrencyFixture();
    $payloads = array_fill(0, 4, $fixture);

    $claims = task0039RunConcurrentWorkers(task0039ClaimWorkerScript(), $payloads);
    expect(array_unique(array_column($claims, 'claim_id')))->toHaveCount(1)
        ->and(DB::table('campaign_schedule_due_claims')->count())->toBe(1);

    $intents = task0039RunConcurrentWorkers(task0039EmitWorkerScript(), $payloads);

    expect(array_unique(array_column($intents, 'intent_id')))->toHaveCount(1)
        ->and(array_unique(array_column($intents, 'outbox_id')))->toHaveCount(1)
        ->and(DB::table('campaign_schedule_execution_intents')->count())->toBe(1)
        ->and(DB::table('outbox_messages')
            ->where('topic', 'publishing.campaign_schedule.execution_intent.ready')
            ->count())->toBe(1)
        ->and(DB::table('campaign_schedule_due_claims')->value('state'))->toBe('emitted');
});