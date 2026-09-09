<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run PostgreSQL concurrency certification.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(RedisManager::class)->connection('locks')->flushdb();
});

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<array<string, mixed>>
 */
function deliveryAdmissionConcurrencyRun(string $script, array $payloads): array
{
    $path = tempnam(sys_get_temp_dir(), 'vsn-delivery-admission-concurrency-');
    if ($path === false) {
        throw new RuntimeException('Unable to create concurrency worker script.');
    }

    if (file_put_contents($path, $script) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to write concurrency worker script.');
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
                throw new RuntimeException('Concurrency worker returned a non-object payload.');
            }

            $results[] = $decoded;
        }

        return $results;
    } finally {
        @unlink($path);
    }
}

/** @return array{workspace_id: string, context: TenantContext} */
function deliveryAdmissionConcurrencyTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Concurrency '.$suffix,
        'slug' => 'concurrency-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Concurrency Workspace '.$suffix,
        'slug' => 'concurrency-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Concurrency Brand '.$suffix,
        'slug' => 'concurrency-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'concurrency-'.$suffix,
        ),
    ];
}

/** @return array{message_snapshot_id: string, recipient_snapshot_id: string} */
function deliveryAdmissionConcurrencySnapshots(array $fixture, string $businessIntentKey): array
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Concurrent Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($businessIntentKey).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        $businessIntentKey,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Concurrent admission'],
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

function deliveryAdmissionConcurrencyOperation(array $fixture, string $businessIntentKey): DeliveryOperation
{
    $snapshots = deliveryAdmissionConcurrencySnapshots($fixture, $businessIntentKey);

    return app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $snapshots['message_snapshot_id'],
        $snapshots['recipient_snapshot_id'],
    );
}

/** @return array{provider_id: string, connection_id: string, quota_id: string} */
function deliveryAdmissionConcurrencyProvider(array $fixture, string $suffix, string $remaining): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $capabilityId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'concurrency-'.$suffix,
        'display_name' => 'Concurrency Provider '.$suffix,
        'category' => 'delivery',
        'metadata' => '{}',
        'source_url' => 'https://example.test/provider/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_connections')->insert([
        'id' => $connectionId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_id' => $providerId,
        'name' => 'Concurrency Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://concurrency/'.$suffix,
        'requested_scopes' => '[]',
        'granted_scopes' => '[]',
        'roles' => '[]',
        'access_tier' => null,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'provider_review_status' => null,
        'token_expires_at' => null,
        'refresh_supported' => false,
        'last_rotated_at' => null,
        'metadata' => '{}',
        'source_url' => 'https://example.test/connection/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_capabilities')->insert([
        'id' => $capabilityId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'operation' => 'email.send',
        'support_status' => 'supported',
        'required_scopes' => '[]',
        'required_roles' => '[]',
        'constraints' => '{}',
        'source_url' => 'https://example.test/capability/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('provider_quotas')->insert([
        'id' => $quotaId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'operation' => 'email.send',
        'scope_type' => 'account',
        'scope_reference' => null,
        'unit' => 'request',
        'window_type' => 'fixed',
        'window_seconds' => 60,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => null,
        'used_value' => null,
        'remaining_value' => $remaining,
        'resets_at' => $now->copy()->addMinute(),
        'dynamically_discovered' => true,
        'discovery_key' => 'concurrency-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'quota_id' => $quotaId,
    ];
}

/** @return array<string, mixed> */
function deliveryAdmissionConcurrencyOperationPayload(DeliveryOperation $operation): array
{
    return [
        'id' => $operation->id,
        'workspace_id' => $operation->workspaceId,
        'message_snapshot_id' => $operation->messageSnapshotId,
        'recipient_snapshot_id' => $operation->recipientSnapshotId,
        'provider_id' => $operation->providerId,
        'provider_connection_id' => $operation->providerConnectionId,
        'channel' => $operation->channel->value,
        'idempotency_key' => $operation->idempotencyKey,
        'scheduled_not_before_at' => $operation->scheduledNotBeforeAt->format(DATE_ATOM),
        'priority_class' => $operation->priorityClass->value,
        'state' => $operation->state->value,
        'queue_name' => $operation->queueName,
        'queue_partition_key' => $operation->queuePartitionKey,
        'backpressure_reason' => $operation->backpressureReason,
        'backpressured_at' => $operation->backpressuredAt?->format(DATE_ATOM),
        'version' => $operation->version,
        'created_at' => $operation->createdAt->format(DATE_ATOM),
        'updated_at' => $operation->updatedAt->format(DATE_ATOM),
    ];
}

it('collapses simultaneous duplicate enqueue into one durable PostgreSQL operation', function () {
    $fixture = deliveryAdmissionConcurrencyTenant('enqueue');
    $snapshots = deliveryAdmissionConcurrencySnapshots($fixture, 'concurrent-enqueue');
    $barrierKey = 'test:delivery:enqueue:'.Str::uuid();

    $payload = [
        'organization_id' => $fixture['context']->organizationId,
        'workspace_id' => $fixture['context']->workspaceId,
        'brand_id' => $fixture['context']->brandId,
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
        throw new RuntimeException('Concurrent enqueue barrier timed out.');
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

    $results = deliveryAdmissionConcurrencyRun($script, [$payload, $payload]);

    expect(array_values(array_unique(array_column($results, 'operation_id'))))->toHaveCount(1)
        ->and(array_values(array_unique(array_column($results, 'idempotency_key'))))->toHaveCount(1)
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('message_snapshot_id', $snapshots['message_snapshot_id'])
            ->where('recipient_snapshot_id', $snapshots['recipient_snapshot_id'])
            ->count())->toBe(1);
});

it('serializes simultaneous PostgreSQL quota admission so a one-unit budget cannot be exceeded', function () {
    $fixture = deliveryAdmissionConcurrencyTenant('quota');
    $provider = deliveryAdmissionConcurrencyProvider($fixture, 'quota', '1');
    $first = deliveryAdmissionConcurrencyOperation($fixture, 'concurrent-quota-a');
    $second = deliveryAdmissionConcurrencyOperation($fixture, 'concurrent-quota-b');
    $barrierKey = 'test:delivery:quota:'.Str::uuid();

    $common = [
        'organization_id' => $fixture['context']->organizationId,
        'workspace_id' => $fixture['context']->workspaceId,
        'brand_id' => $fixture['context']->brandId,
        'actor_id' => $fixture['context']->actorId,
        'barrier_key' => $barrierKey,
        'barrier_size' => 2,
    ];

    $payloads = [
        $common + ['operation' => deliveryAdmissionConcurrencyOperationPayload($first)],
        $common + ['operation' => deliveryAdmissionConcurrencyOperationPayload($second)],
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
        throw new RuntimeException('Concurrent quota barrier timed out.');
    }
    usleep(10000);
}
$context = new App\Modules\Identity\Domain\Tenancy\TenantContext(
    organizationId: $payload['organization_id'],
    workspaceId: $payload['workspace_id'],
    brandId: $payload['brand_id'],
    actorId: $payload['actor_id'],
);
$data = $payload['operation'];
$operation = new App\Modules\DeliveryEngine\Domain\DeliveryOperation(
    id: $data['id'],
    workspaceId: $data['workspace_id'],
    messageSnapshotId: $data['message_snapshot_id'],
    recipientSnapshotId: $data['recipient_snapshot_id'],
    providerId: $data['provider_id'],
    providerConnectionId: $data['provider_connection_id'],
    channel: App\Modules\DeliveryEngine\Domain\DeliveryChannel::from($data['channel']),
    idempotencyKey: $data['idempotency_key'],
    scheduledNotBeforeAt: new DateTimeImmutable($data['scheduled_not_before_at']),
    priorityClass: App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass::from($data['priority_class']),
    state: App\Modules\DeliveryEngine\Domain\DeliveryOperationState::from($data['state']),
    queueName: $data['queue_name'],
    queuePartitionKey: $data['queue_partition_key'],
    backpressureReason: $data['backpressure_reason'],
    backpressuredAt: $data['backpressured_at'] === null ? null : new DateTimeImmutable($data['backpressured_at']),
    version: $data['version'],
    createdAt: new DateTimeImmutable($data['created_at']),
    updatedAt: new DateTimeImmutable($data['updated_at']),
);
$result = $app->make(App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation::class)->handle($context, $operation);
echo json_encode([
    'operation_id' => $result->operation->id,
    'admitted' => $result->admitted,
    'state' => $result->operation->state->value,
    'reason' => $result->backpressureReason,
], JSON_THROW_ON_ERROR);
PHP;

    $results = deliveryAdmissionConcurrencyRun($script, $payloads);
    $admitted = array_values(array_filter($results, static fn (array $row): bool => $row['admitted'] === true));
    $backpressured = array_values(array_filter($results, static fn (array $row): bool => $row['admitted'] === false));

    expect($admitted)->toHaveCount(1)
        ->and($backpressured)->toHaveCount(1)
        ->and($admitted[0]['state'])->toBe(DeliveryOperationState::Leased->value)
        ->and($backpressured[0]['state'])->toBe(DeliveryOperationState::Backpressured->value)
        ->and($backpressured[0]['reason'])->toBe('quota_exhausted')
        ->and((float) DB::table('delivery_operation_quota_consumptions')
            ->where('quota_id', $provider['quota_id'])
            ->sum('units'))->toBe(1.0)
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('quota_id', $provider['quota_id'])
            ->count())->toBe(1)
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('state', DeliveryOperationState::Leased->value)
            ->count())->toBe(1)
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('state', DeliveryOperationState::Backpressured->value)
            ->count())->toBe(1);
});
