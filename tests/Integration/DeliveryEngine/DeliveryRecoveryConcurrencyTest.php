<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Application\ResolveDeliveryReconciliation;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run PostgreSQL/Redis recovery race certification.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(RedisManager::class)->connection('locks')->flushdb();
});

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<array<string, mixed>>
 */
function deliveryRecoveryRaceRun(string $script, array $payloads): array
{
    $path = tempnam(sys_get_temp_dir(), 'vsn-delivery-recovery-race-');
    if ($path === false) {
        throw new RuntimeException('Unable to create delivery recovery race worker script.');
    }

    if (file_put_contents($path, $script) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to write delivery recovery race worker script.');
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

            $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('Delivery recovery race worker returned an invalid payload.');
            }

            $results[] = $result;
        }

        return $results;
    } finally {
        @unlink($path);
    }
}

/** @return array{workspace_id: string, context: TenantContext} */
function deliveryRecoveryRaceTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Recovery Race '.$suffix,
        'slug' => 'recovery-race-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Recovery Race Workspace '.$suffix,
        'slug' => 'recovery-race-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Recovery Race Brand '.$suffix,
        'slug' => 'recovery-race-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'recovery-race-'.$suffix,
        ),
    ];
}

/** @return array{provider_id: string, connection_id: string} */
function deliveryRecoveryRaceProvider(array $fixture, string $suffix): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'recovery-race-'.$suffix,
        'display_name' => 'Recovery Race Provider '.$suffix,
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
        'name' => 'Recovery Race Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://recovery-race/'.$suffix,
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
        'id' => (string) Str::uuid(),
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
        'id' => (string) Str::uuid(),
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
        'remaining_value' => '10',
        'resets_at' => $now->copy()->addMinute(),
        'dynamically_discovered' => true,
        'discovery_key' => 'recovery-race-'.$suffix,
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
    ];
}

/** @return array{source: array{provider_id: string, connection_id: string}, operation_id: string, attempt_id: string} */
function deliveryRecoveryRaceAmbiguousOperation(array $fixture, string $suffix): array
{
    $source = deliveryRecoveryRaceProvider($fixture, $suffix.'-source');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Recovery Race Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'recovery-race-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Recovery race '.$suffix],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );
    $queued = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $snapshots->message->id,
        $snapshots->recipient->id,
    );
    $leased = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $queued)->operation;
    $attemptId = (string) Str::uuid();

    app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $leased->id,
        $attemptId,
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unknown,
            requestMayHaveReachedProvider: true,
        ),
    );

    return [
        'source' => $source,
        'operation_id' => $leased->id,
        'attempt_id' => $attemptId,
    ];
}

/** @return array<string, mixed> */
function deliveryRecoveryRaceWorkerPayload(
    array $fixture,
    array $scenario,
    array $alternate,
    string $mode,
    string $barrierKey,
): array {
    return [
        'organization_id' => $fixture['context']->organizationId,
        'workspace_id' => $fixture['context']->workspaceId,
        'brand_id' => $fixture['context']->brandId,
        'actor_id' => $fixture['context']->actorId,
        'operation_id' => $scenario['operation_id'],
        'attempt_id' => $scenario['attempt_id'],
        'alternate_provider_id' => $alternate['provider_id'],
        'alternate_connection_id' => $alternate['connection_id'],
        'mode' => $mode,
        'barrier_key' => $barrierKey,
        'barrier_size' => 2,
    ];
}

function deliveryRecoveryRaceWorkerScript(): string
{
    return <<<'PHP'
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
        throw new RuntimeException('Delivery recovery race barrier timed out.');
    }
    usleep(10000);
}
$context = new App\Modules\Identity\Domain\Tenancy\TenantContext(
    organizationId: $payload['organization_id'],
    workspaceId: $payload['workspace_id'],
    brandId: $payload['brand_id'],
    actorId: $payload['actor_id'],
);
try {
    if ($payload['mode'] === 'resolve_accepted') {
        $result = $app->make(App\Modules\DeliveryEngine\Application\ResolveDeliveryReconciliation::class)->handle(
            $context,
            $payload['operation_id'],
            $payload['attempt_id'],
            new App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence(
                providerAccepted: true,
                probeAttemptNumber: 1,
                reason: 'concurrent_provider_acceptance_confirmed',
            ),
        );
        echo json_encode(['ok' => true, 'state' => $result->operation->state->value], JSON_THROW_ON_ERROR);
        exit(0);
    }

    $result = $app->make(App\Modules\DeliveryEngine\Application\FailoverDeliveryOperation::class)->handle(
        $context,
        $payload['operation_id'],
        $payload['attempt_id'],
        $payload['alternate_provider_id'],
        $payload['alternate_connection_id'],
    );
    echo json_encode(['ok' => true, 'state' => $result->operation->state->value], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP;
}

it('serializes accepted reconciliation against concurrent failover without replaying the logical operation', function () {
    $fixture = deliveryRecoveryRaceTenant('accepted-vs-failover');
    $scenario = deliveryRecoveryRaceAmbiguousOperation($fixture, 'accepted-vs-failover');
    $alternate = deliveryRecoveryRaceProvider($fixture, 'accepted-vs-failover-alternate');
    $barrierKey = 'test:delivery:recovery-race:'.Str::uuid();

    $results = deliveryRecoveryRaceRun(deliveryRecoveryRaceWorkerScript(), [
        deliveryRecoveryRaceWorkerPayload($fixture, $scenario, $alternate, 'resolve_accepted', $barrierKey),
        deliveryRecoveryRaceWorkerPayload($fixture, $scenario, $alternate, 'failover', $barrierKey),
    ]);

    $operation = DB::table('delivery_operations')->where('id', $scenario['operation_id'])->first();
    $reconciliation = DB::table('delivery_reconciliations')->where('attempt_id', $scenario['attempt_id'])->first();

    expect($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'])->toBeFalse()
        ->and($operation)->not->toBeNull()
        ->and($operation->state)->toBe(DeliveryOperationState::Accepted->value)
        ->and($operation->provider_connection_id)->toBe($scenario['source']['connection_id'])
        ->and($reconciliation)->not->toBeNull()
        ->and($reconciliation->resolution)->toBe('accepted')
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('operation_id', $scenario['operation_id'])
            ->where('provider_connection_id', $alternate['connection_id'])
            ->count())->toBe(0)
        ->and(DB::table('delivery_attempts')->where('operation_id', $scenario['operation_id'])->count())->toBe(1);
});

it('allows only one concurrent explicit failover after retry-safe non-acceptance is proven', function () {
    $fixture = deliveryRecoveryRaceTenant('duplicate-failover');
    $scenario = deliveryRecoveryRaceAmbiguousOperation($fixture, 'duplicate-failover');
    $alternate = deliveryRecoveryRaceProvider($fixture, 'duplicate-failover-alternate');

    $resolved = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['operation_id'],
        $scenario['attempt_id'],
        new DeliveryReconciliationEvidence(
            acceptanceKnownNotOccurred: true,
            retrySafe: true,
            probeAttemptNumber: 1,
            reason: 'provider_non_acceptance_proven_for_race',
        ),
    );
    expect($resolved->operation->state)->toBe(DeliveryOperationState::Held);

    $barrierKey = 'test:delivery:duplicate-failover:'.Str::uuid();
    $payload = deliveryRecoveryRaceWorkerPayload($fixture, $scenario, $alternate, 'failover', $barrierKey);
    $results = deliveryRecoveryRaceRun(deliveryRecoveryRaceWorkerScript(), [$payload, $payload]);
    $successful = array_values(array_filter($results, fn (array $result): bool => $result['ok'] === true));
    $failed = array_values(array_filter($results, fn (array $result): bool => $result['ok'] === false));
    $operation = DB::table('delivery_operations')->where('id', $scenario['operation_id'])->first();

    expect($successful)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($operation)->not->toBeNull()
        ->and($operation->state)->toBe(DeliveryOperationState::Leased->value)
        ->and($operation->provider_connection_id)->toBe($alternate['connection_id'])
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('operation_id', $scenario['operation_id'])
            ->where('provider_connection_id', $alternate['connection_id'])
            ->where('attempt_number', 2)
            ->count())->toBe(1)
        ->and(DB::table('delivery_attempts')->where('operation_id', $scenario['operation_id'])->count())->toBe(1);
});
