<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0024 queue certification.');
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
function phase04QueueCertificationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'PHASE-04 Queue '.$suffix,
        'slug' => 'phase04-queue-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'PHASE-04 Queue Workspace '.$suffix,
        'slug' => 'phase04-queue-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'PHASE-04 Queue Brand '.$suffix,
        'slug' => 'phase04-queue-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'phase04-queue-'.$suffix,
        ),
    ];
}

/** @return array{provider_id: string, connection_id: string, quota_id: string} */
function phase04QueueCertificationProvider(array $fixture, string $suffix, string $remaining): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'phase04-queue-'.$suffix,
        'display_name' => 'PHASE-04 Queue Provider '.$suffix,
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
        'name' => 'PHASE-04 Queue Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://phase04-queue/'.$suffix,
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
        'discovery_key' => 'phase04-queue-'.$suffix,
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

function phase04QueueCertificationOperation(array $fixture, string $suffix): DeliveryOperation
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'PHASE-04 Queue '.$suffix);
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'phase04-queue-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'PHASE-04 Queue '.$suffix],
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

it('certifies concurrency saturation backpressures without loss and drains after capacity is released', function () {
    $fixture = phase04QueueCertificationTenant('capacity');
    phase04QueueCertificationProvider($fixture, 'capacity', '100');
    $operations = [];

    for ($index = 0; $index < 4; $index++) {
        $operations[] = phase04QueueCertificationOperation($fixture, 'capacity-'.$index);
    }

    $results = array_map(
        fn (DeliveryOperation $operation) => app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation),
        $operations,
    );
    $admitted = array_values(array_filter($results, static fn ($result): bool => $result->admitted));
    $blocked = array_values(array_filter($results, static fn ($result): bool => ! $result->admitted));

    expect($admitted)->toHaveCount(2)
        ->and($blocked)->toHaveCount(2)
        ->and(array_unique(array_map(static fn ($result) => $result->backpressureReason, $blocked)))
        ->toBe(['concurrency_capacity_exhausted'])
        ->and(DB::table('delivery_operations')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(4)
        ->and(DB::table('delivery_attempts')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(0);

    $coordinator = app(DeliveryAdmissionCoordinator::class);
    foreach ($admitted as $result) {
        $coordinator->release($fixture['workspace_id'], $result->operation->id);
    }

    foreach ($blocked as $result) {
        $drained = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $result->operation);
        expect($drained->admitted)->toBeTrue();
        $coordinator->release($fixture['workspace_id'], $drained->operation->id);
    }

    $operationIds = DB::table('delivery_operations')
        ->where('workspace_id', $fixture['workspace_id'])
        ->pluck('id')
        ->all();

    expect(DB::table('delivery_operations')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('state', DeliveryOperationState::Backpressured->value)
        ->count())->toBe(0)
        ->and(DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('state', DeliveryOperationState::Leased->value)
            ->count())->toBe(4)
        ->and(array_unique($operationIds))->toHaveCount(4)
        ->and(DB::table('delivery_attempts')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(0);
});

it('certifies fixed-window quota holds remain duplicate-safe and physical-attempt-free', function () {
    config(['delivery.admission.concurrency_enabled' => false]);

    $fixture = phase04QueueCertificationTenant('quota');
    $provider = phase04QueueCertificationProvider($fixture, 'quota', '1');
    $firstOperation = phase04QueueCertificationOperation($fixture, 'quota-0');
    $heldOperation = phase04QueueCertificationOperation($fixture, 'quota-1');

    $first = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $firstOperation);
    $held = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $heldOperation);

    expect($first->admitted)->toBeTrue()
        ->and($held->admitted)->toBeFalse()
        ->and($held->backpressureReason)->toBe('quota_exhausted')
        ->and($held->operation->id)->toBe($heldOperation->id);

    for ($retry = 0; $retry < 3; $retry++) {
        $rechecked = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $held->operation);
        expect($rechecked->admitted)->toBeFalse()
            ->and($rechecked->backpressureReason)->toBe('quota_exhausted')
            ->and($rechecked->operation->id)->toBe($heldOperation->id);
        $held = $rechecked;
    }

    expect(DB::table('delivery_operation_quota_consumptions')
        ->where('quota_id', $provider['quota_id'])
        ->count())->toBe(1)
        ->and((float) DB::table('delivery_operation_quota_consumptions')
            ->where('quota_id', $provider['quota_id'])
            ->sum('units'))->toBe(1.0)
        ->and(DB::table('delivery_operations')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(2)
        ->and(DB::table('delivery_attempts')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(0);
});
