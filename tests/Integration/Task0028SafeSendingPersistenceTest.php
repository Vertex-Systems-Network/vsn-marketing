<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\Frequency\EvaluateFrequencyPolicy;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyCounterSnapshot;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyPolicy;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0028 PostgreSQL certification.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(RedisManager::class)->connection('locks')->flushdb();
});

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<array<string, mixed>>
 */
function task0028PersistenceRunConcurrent(string $script, array $payloads): array
{
    $path = tempnam(sys_get_temp_dir(), 'vsn-task0028-safe-sending-');
    if ($path === false) {
        throw new RuntimeException('Unable to create TASK-0028 concurrency worker script.');
    }

    if (file_put_contents($path, $script) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to write TASK-0028 concurrency worker script.');
    }

    $processes = [];

    try {
        foreach ($payloads as $payload) {
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, $path, $encoded], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $exitCode = $process->wait();
            if ($exitCode !== 0) {
                throw new RuntimeException(trim($process->getErrorOutput().' '.$process->getOutput()));
            }

            $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('TASK-0028 concurrency worker returned a non-object payload.');
            }

            $results[] = $decoded;
        }

        return $results;
    } finally {
        @unlink($path);
    }
}

/** @return array{organization_id: string, workspace_id: string, brand_id: string, context: TenantContext} */
function task0028PersistenceTenant(string $suffix, ?string $organizationId = null): array
{
    $organizationId ??= (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'name' => 'TASK-0028 Persistence '.$suffix,
            'slug' => 'task0028-persistence-'.$suffix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0028 Persistence Workspace '.$suffix,
        'slug' => 'task0028-persistence-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0028 Persistence Brand '.$suffix,
        'slug' => 'task0028-persistence-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'organization_id' => $organizationId,
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'task0028-persistence-'.$suffix,
        ),
    ];
}

/** @return array{message_snapshot_id: string, recipient_snapshot_id: string} */
function task0028PersistenceSnapshots(array $fixture, string $suffix): array
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'TASK-0028 Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'task0028-'.$suffix,
        MessageIntentType::Marketing,
        DeliveryChannel::Email,
        ['subject' => 'TASK-0028 certification'],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    return [
        'message_snapshot_id' => $snapshots->message->id,
        'recipient_snapshot_id' => $snapshots->recipient->id,
    ];
}

it('collapses concurrent retries to one operation and preserves one frequency-cap identity', function () {
    $fixture = task0028PersistenceTenant('concurrent');
    $snapshots = task0028PersistenceSnapshots($fixture, 'concurrent');
    $barrierKey = 'test:task0028:enqueue:'.Str::uuid();

    $payload = [
        'organization_id' => $fixture['organization_id'],
        'workspace_id' => $fixture['workspace_id'],
        'brand_id' => $fixture['brand_id'],
        'actor_id' => $fixture['context']->actorId,
        'message_snapshot_id' => $snapshots['message_snapshot_id'],
        'recipient_snapshot_id' => $snapshots['recipient_snapshot_id'],
        'barrier_key' => $barrierKey,
        'barrier_size' => 2,
    ];

    $script = <<<'PHP'
<?php
$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$redis = $app->make(Illuminate\Redis\RedisManager::class)->connection('locks');
$redis->incr($payload['barrier_key']);
$deadline = microtime(true) + 10;
while ((int) $redis->get($payload['barrier_key']) < $payload['barrier_size']) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('TASK-0028 enqueue barrier timed out.');
    }
    usleep(10000);
}
$context = new App\Modules\Identity\Domain\Tenancy\TenantContext(
    organizationId: $payload['organization_id'],
    workspaceId: $payload['workspace_id'],
    brandId: $payload['brand_id'],
    actorId: $payload['actor_id'],
);
$operation = $app->make(App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation::class)->handle(
    $context,
    $payload['message_snapshot_id'],
    $payload['recipient_snapshot_id'],
);
echo json_encode([
    'operation_id' => $operation->id,
    'idempotency_key' => $operation->idempotencyKey,
], JSON_THROW_ON_ERROR);
PHP;

    $results = task0028PersistenceRunConcurrent($script, [$payload, $payload]);
    $operationIds = array_values(array_unique(array_column($results, 'operation_id')));
    $idempotencyKeys = array_values(array_unique(array_column($results, 'idempotency_key')));

    expect($operationIds)->toHaveCount(1)
        ->and($idempotencyKeys)->toHaveCount(1)
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('message_snapshot_id', $snapshots['message_snapshot_id'])
            ->where('recipient_snapshot_id', $snapshots['recipient_snapshot_id'])
            ->count())->toBe(1);

    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $policy = new FrequencyPolicy(
        id: 'task0028-policy',
        workspaceId: $fixture['workspace_id'],
        messagePurpose: MessageIntentType::Marketing,
        recipientScope: 'recipient:'.$snapshots['recipient_snapshot_id'],
        windowSeconds: 3600,
        maxMessages: 1,
        version: 'task0028-v1',
        effectiveAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
    );
    $window = $policy->windowFor($at);
    $counter = new FrequencyCounterSnapshot(
        workspaceId: $fixture['workspace_id'],
        policyId: $policy->id,
        recipientScope: $policy->recipientScope,
        windowStart: $window['start'],
        windowEnd: $window['end'],
        count: 1,
        countedOperationKeys: [$operationIds[0]],
        observedAt: $at,
    );
    $evaluator = new EvaluateFrequencyPolicy;

    $replay = $evaluator->evaluate(
        $fixture['workspace_id'],
        MessageIntentType::Marketing,
        $policy->recipientScope,
        $operationIds[0],
        $policy,
        $counter,
        $at,
    );
    $newAttempt = $evaluator->evaluate(
        $fixture['workspace_id'],
        MessageIntentType::Marketing,
        $policy->recipientScope,
        'new-physical-operation',
        $policy,
        $counter,
        $at,
    );

    expect($replay->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($replay->replay)->toBeTrue()
        ->and($replay->currentCount)->toBe(1)
        ->and($replay->nextCount)->toBe(1)
        ->and($newAttempt->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($newAttempt->reasons)->toContain('frequency_limit_reached');
});

it('keeps persisted execution snapshots and frequency policy isolated by workspace', function () {
    $left = task0028PersistenceTenant('left');
    $right = task0028PersistenceTenant('right', $left['organization_id']);
    $snapshots = task0028PersistenceSnapshots($left, 'workspace-isolation');

    expect(fn () => app(EnqueueDeliveryOperation::class)->handle(
        $right['context'],
        $snapshots['message_snapshot_id'],
        $snapshots['recipient_snapshot_id'],
    ))->toThrow(AuthorizationException::class, 'Delivery execution snapshot access denied.');

    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $policy = new FrequencyPolicy(
        id: 'task0028-isolation-policy',
        workspaceId: $left['workspace_id'],
        messagePurpose: MessageIntentType::Marketing,
        recipientScope: 'recipient:'.$snapshots['recipient_snapshot_id'],
        windowSeconds: 3600,
        maxMessages: 1,
        version: 'task0028-isolation-v1',
        effectiveAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
    );

    $result = (new EvaluateFrequencyPolicy)->evaluate(
        $right['workspace_id'],
        MessageIntentType::Marketing,
        $policy->recipientScope,
        'foreign-workspace-attempt',
        $policy,
        null,
        $at,
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->reasons)->toContain('frequency_policy_workspace_mismatch')
        ->and(DB::table('delivery_operations')->where('workspace_id', $right['workspace_id'])->count())->toBe(0);
});
