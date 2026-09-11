<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run queue saturation certification.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(RedisManager::class)->connection('locks')->flushdb();

    config([
        'delivery.admission.concurrency_enabled' => true,
        'delivery.admission.global_concurrency_limit' => 2,
        'delivery.admission.workspace_concurrency_limit' => 2,
        'delivery.admission.reservation_ttl_seconds' => 5,
    ]);
});

/** @return array{workspace_id: string, context: TenantContext} */
function task0023SaturationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Saturation '.$suffix,
        'slug' => 'saturation-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Saturation Workspace '.$suffix,
        'slug' => 'saturation-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Saturation Brand '.$suffix,
        'slug' => 'saturation-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'saturation-'.$suffix,
        ),
    ];
}

/** @return array{provider_id: string, connection_id: string, quota_id: string} */
function task0023SaturationProvider(array $fixture, string $suffix, string $remaining): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'saturation-'.$suffix,
        'display_name' => 'Saturation Provider '.$suffix,
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
        'name' => 'Saturation Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://saturation/'.$suffix,
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
        'id' => $quotaId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'operation' => 'email.send',
        'scope_type' => 'account',
        'scope_reference' => null,
        'unit' => 'request',
        'window_type' => 'fixed',
        'window_seconds' => 3600,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => null,
        'used_value' => null,
        'remaining_value' => $remaining,
        'resets_at' => $now->copy()->addHour(),
        'dynamically_discovered' => true,
        'discovery_key' => 'saturation-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'provider_id' => $providerId,
        'connection_id' => $connectionId,
        'quota_id' => $quotaId,
    ];
}

function task0023SaturationOperation(array $fixture, string $suffix): DeliveryOperation
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Saturation '.$suffix);
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'saturation-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Saturation '.$suffix],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );

    return app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $snapshots->message->id,
        $snapshots->recipient->id,
    );
}

/** @param list<float|int> $values */
function task0023SaturationPercentile(array $values, float $percentile): float
{
    sort($values, SORT_NUMERIC);
    $rank = max(0, min(count($values) - 1, (int) ceil($percentile * count($values)) - 1));

    return (float) $values[$rank];
}

it('bounds queue growth under concurrency saturation and drains backlog after capacity is released', function () {
    $fixture = task0023SaturationTenant('capacity');
    task0023SaturationProvider($fixture, 'capacity', '100');
    $operations = [];
    $baseline = now();

    for ($index = 0; $index < 6; $index++) {
        $operation = task0023SaturationOperation($fixture, 'capacity-'.$index);
        $queuedAt = $baseline->copy()->subSeconds(6 - $index);
        DB::table('delivery_operations')->where('id', $operation->id)->update([
            'created_at' => $queuedAt,
            'updated_at' => $queuedAt,
            'scheduled_not_before_at' => $queuedAt,
        ]);
        $operations[] = $operation;
    }

    $startedAt = hrtime(true);
    $results = array_map(
        fn (DeliveryOperation $operation) => app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation),
        $operations,
    );
    $elapsedSeconds = max(0.000001, (hrtime(true) - $startedAt) / 1_000_000_000);

    $admitted = array_values(array_filter($results, static fn ($result): bool => $result->admitted));
    $blocked = array_values(array_filter($results, static fn ($result): bool => ! $result->admitted));
    $ages = [];
    foreach (DB::table('delivery_operations')->where('workspace_id', $fixture['workspace_id'])->get() as $row) {
        $created = new DateTimeImmutable((string) $row->created_at);
        $updated = new DateTimeImmutable((string) $row->updated_at);
        $ages[] = max(0, $updated->getTimestamp() - $created->getTimestamp());
    }

    $p50 = task0023SaturationPercentile($ages, 0.50);
    $p95 = task0023SaturationPercentile($ages, 0.95);
    $p99 = task0023SaturationPercentile($ages, 0.99);
    $measuredThroughput = count($results) / $elapsedSeconds;

    expect($admitted)->toHaveCount(2)
        ->and($blocked)->toHaveCount(4)
        ->and(array_unique(array_map(static fn ($result) => $result->backpressureReason, $blocked)))
        ->toBe(['concurrency_capacity_exhausted'])
        ->and(DB::table('delivery_operations')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(6)
        ->and($p50)->toBeGreaterThan(0.0)
        ->and($p95)->toBeGreaterThanOrEqual($p50)
        ->and($p99)->toBeGreaterThanOrEqual($p95)
        ->and($measuredThroughput)->toBeGreaterThan(0.0);

    $coordinator = app(DeliveryAdmissionCoordinator::class);
    foreach ($admitted as $result) {
        $coordinator->release($fixture['workspace_id'], $result->operation->id);
    }

    foreach ($blocked as $result) {
        $drained = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $result->operation);
        expect($drained->admitted)->toBeTrue();
        $coordinator->release($fixture['workspace_id'], $drained->operation->id);
    }

    expect(DB::table('delivery_operations')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('state', DeliveryOperationState::Backpressured->value)
        ->count())->toBe(0)
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('state', DeliveryOperationState::Leased->value)
            ->count())->toBe(6);
});

it('holds quota-saturated work without hot-loop attempt amplification', function () {
    config(['delivery.admission.concurrency_enabled' => false]);

    $fixture = task0023SaturationTenant('quota');
    $provider = task0023SaturationProvider($fixture, 'quota', '1');
    $operations = [
        task0023SaturationOperation($fixture, 'quota-0'),
        task0023SaturationOperation($fixture, 'quota-1'),
        task0023SaturationOperation($fixture, 'quota-2'),
    ];

    $first = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operations[0]);
    $second = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operations[1]);
    $third = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operations[2]);

    expect($first->admitted)->toBeTrue()
        ->and($second->admitted)->toBeFalse()
        ->and($third->admitted)->toBeFalse()
        ->and($second->backpressureReason)->toBe('quota_exhausted')
        ->and($third->backpressureReason)->toBe('quota_exhausted');

    for ($retry = 0; $retry < 5; $retry++) {
        $held = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $second->operation);
        expect($held->admitted)->toBeFalse()
            ->and($held->backpressureReason)->toBe('quota_exhausted');
    }

    $logicalOperations = DB::table('delivery_operations')->where('workspace_id', $fixture['workspace_id'])->count();
    $physicalAttempts = DB::table('delivery_attempts')->where('workspace_id', $fixture['workspace_id'])->count();
    $retryAmplification = $logicalOperations === 0 ? 0.0 : (float) $physicalAttempts / $logicalOperations;

    expect(DB::table('delivery_operation_quota_consumptions')
        ->where('quota_id', $provider['quota_id'])
        ->count())->toBe(1)
        ->and((float) DB::table('delivery_operation_quota_consumptions')
            ->where('quota_id', $provider['quota_id'])
            ->sum('units'))->toBe(1.0)
        ->and($logicalOperations)->toBe(3)
        ->and($physicalAttempts)->toBe(0)
        ->and($retryAmplification)->toBe(0.0);
});

it('records one physical attempt for replayed retryable failure evidence instead of amplifying retries', function () {
    config(['delivery.admission.concurrency_enabled' => false]);

    $fixture = task0023SaturationTenant('retry');
    task0023SaturationProvider($fixture, 'retry', '100');
    $operation = task0023SaturationOperation($fixture, 'retry-0');
    $admitted = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);
    expect($admitted->admitted)->toBeTrue();

    $observation = new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Retryable,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 1,
    );
    $attemptId = (string) Str::uuid();
    $first = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $admitted->operation->id,
        $attemptId,
        $observation,
    );
    $replayed = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $admitted->operation->id,
        (string) Str::uuid(),
        $observation,
    );

    $attemptCount = DB::table('delivery_attempts')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('operation_id', $admitted->operation->id)
        ->count();

    expect($first->changed)->toBeTrue()
        ->and($replayed->changed)->toBeFalse()
        ->and($replayed->attemptId)->toBe($attemptId)
        ->and($attemptCount)->toBe(1)
        ->and($attemptCount / 1)->toBe(1);
});
