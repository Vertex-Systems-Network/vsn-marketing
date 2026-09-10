<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerKey;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function recoveryAdmissionSafetyTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Recovery Safety '.$suffix,
        'slug' => 'recovery-safety-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Recovery Safety Workspace '.$suffix,
        'slug' => 'recovery-safety-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Recovery Safety Brand '.$suffix,
        'slug' => 'recovery-safety-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'recovery-safety-'.$suffix,
        ),
    ];
}

function recoveryAdmissionSafetyProvider(array $fixture, string $suffix, string $connectionId): array
{
    $providerId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'recovery-safety-'.$suffix,
        'display_name' => 'Recovery Safety '.$suffix,
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
        'name' => 'Safety '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://recovery-safety/'.$suffix,
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
        'window_seconds' => 60,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => null,
        'used_value' => null,
        'remaining_value' => '20',
        'resets_at' => $now->copy()->addMinute(),
        'dynamically_discovered' => true,
        'discovery_key' => 'recovery-safety-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return compact('providerId', 'connectionId', 'quotaId');
}

function recoveryAdmissionSafetyOperation(array $fixture, string $suffix)
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Safety Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'recovery-safety-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Recovery safety '.$suffix],
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

it('never readmits accepted reconciliation held or dead-letter terminal states', function () {
    $fixture = recoveryAdmissionSafetyTenant('terminal');
    recoveryAdmissionSafetyProvider(
        $fixture,
        'terminal',
        '00000000-0000-4000-8000-000000000001',
    );
    $operation = recoveryAdmissionSafetyOperation($fixture, 'terminal');
    $leased = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation)->operation;
    $initialQuotaRows = DB::table('delivery_operation_quota_consumptions')->count();

    foreach ([
        DeliveryOperationState::Accepted,
        DeliveryOperationState::Reconciling,
        DeliveryOperationState::Held,
        DeliveryOperationState::DeadLettered,
    ] as $state) {
        DB::table('delivery_operations')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('id', $operation->id)
            ->update(['state' => $state->value]);

        $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $leased);

        expect($result->admitted)->toBeFalse()
            ->and($result->changed)->toBeFalse()
            ->and($result->backpressureReason)->toBe('operation_state_not_admissible')
            ->and($result->providerId)->toBe($leased->providerId)
            ->and($result->providerConnectionId)->toBe($leased->providerConnectionId);
    }

    expect(DB::table('delivery_operation_quota_consumptions')->count())->toBe($initialQuotaRows);
});

it('pins a retry to its original route and preserves binding under backpressure', function () {
    $fixture = recoveryAdmissionSafetyTenant('pin');
    $first = recoveryAdmissionSafetyProvider(
        $fixture,
        'pin-first',
        '00000000-0000-4000-8000-000000000001',
    );
    $second = recoveryAdmissionSafetyProvider(
        $fixture,
        'pin-second',
        '00000000-0000-4000-8000-000000000002',
    );
    $operation = recoveryAdmissionSafetyOperation($fixture, 'pin');
    $leased = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation)->operation;

    $recovered = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Retryable,
            acceptanceKnownNotOccurred: true,
        ),
    );

    DB::table('delivery_operations')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('id', $operation->id)
        ->update(['scheduled_not_before_at' => now()->subSecond()]);
    DB::table('provider_connections')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('id', $first['connectionId'])
        ->update(['readiness_status' => 'unavailable']);

    $retry = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $recovered->operation);

    expect($leased->providerConnectionId)->toBe($first['connectionId'])
        ->and($retry->admitted)->toBeFalse()
        ->and($retry->backpressureReason)->toBe('provider_connection_unavailable')
        ->and($retry->providerId)->toBe($first['providerId'])
        ->and($retry->providerConnectionId)->toBe($first['connectionId'])
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('provider_connection_id', $second['connectionId'])
            ->count())->toBe(0);
});

it('holds a route while its persisted circuit breaker remains open', function () {
    $fixture = recoveryAdmissionSafetyTenant('open');
    $route = recoveryAdmissionSafetyProvider(
        $fixture,
        'open',
        '00000000-0000-4000-8000-000000000001',
    );
    $operation = recoveryAdmissionSafetyOperation($fixture, 'open');
    $leased = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation)->operation;
    $recovered = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unavailable,
            httpStatus: 503,
            acceptanceKnownNotOccurred: true,
        ),
    );
    $breakerId = (new DeliveryCircuitBreakerKey(
        workspaceId: $fixture['workspace_id'],
        providerConnectionId: $route['connectionId'],
        operationClass: 'email.send',
    ))->fingerprint();

    DB::table('delivery_operations')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('id', $operation->id)
        ->update(['scheduled_not_before_at' => now()->subSecond()]);
    DB::table('delivery_circuit_breakers')
        ->where('id', $breakerId)
        ->update([
            'state' => 'open',
            'next_probe_at' => now()->addMinute(),
            'probe_in_flight' => false,
        ]);

    $blocked = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $recovered->operation);

    expect($blocked->admitted)->toBeFalse()
        ->and($blocked->backpressureReason)->toBe('circuit_breaker_open')
        ->and($blocked->providerConnectionId)->toBe($leased->providerConnectionId)
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('operation_id', $operation->id)
            ->count())->toBe(1);
});

it('claims one half-open probe after cooldown and records quota for the new attempt', function () {
    $fixture = recoveryAdmissionSafetyTenant('probe');
    $route = recoveryAdmissionSafetyProvider(
        $fixture,
        'probe',
        '00000000-0000-4000-8000-000000000001',
    );
    $operation = recoveryAdmissionSafetyOperation($fixture, 'probe-one');
    app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);
    $recovered = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unavailable,
            httpStatus: 503,
            acceptanceKnownNotOccurred: true,
        ),
    );
    $breakerId = (new DeliveryCircuitBreakerKey(
        workspaceId: $fixture['workspace_id'],
        providerConnectionId: $route['connectionId'],
        operationClass: 'email.send',
    ))->fingerprint();

    DB::table('delivery_operations')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('id', $operation->id)
        ->update(['scheduled_not_before_at' => now()->subSecond()]);
    DB::table('delivery_circuit_breakers')
        ->where('id', $breakerId)
        ->update([
            'state' => 'open',
            'next_probe_at' => now()->subSecond(),
            'probe_in_flight' => false,
        ]);

    $probe = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $recovered->operation);
    $breaker = DB::table('delivery_circuit_breakers')->where('id', $breakerId)->first();
    $quotaAttempts = DB::table('delivery_operation_quota_consumptions')
        ->where('operation_id', $operation->id)
        ->orderBy('attempt_number')
        ->pluck('attempt_number')
        ->map(fn ($value) => (int) $value)
        ->all();

    $secondOperation = recoveryAdmissionSafetyOperation($fixture, 'probe-two');
    $blockedPeer = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $secondOperation);

    expect($probe->admitted)->toBeTrue()
        ->and($probe->operation->state)->toBe(DeliveryOperationState::Leased)
        ->and($breaker)->not->toBeNull()
        ->and($breaker->state)->toBe('half_open')
        ->and((bool) $breaker->probe_in_flight)->toBeTrue()
        ->and($quotaAttempts)->toBe([1, 2])
        ->and($blockedPeer->admitted)->toBeFalse()
        ->and($blockedPeer->backpressureReason)->toBe('circuit_breaker_open');
});
