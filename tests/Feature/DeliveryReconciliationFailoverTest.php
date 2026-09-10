<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\FailoverDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Application\ResolveDeliveryReconciliation;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function reconciliationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Reconciliation '.$suffix,
        'slug' => 'reconciliation-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Reconciliation Workspace '.$suffix,
        'slug' => 'reconciliation-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Reconciliation Brand '.$suffix,
        'slug' => 'reconciliation-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'reconciliation-'.$suffix,
        ),
    ];
}

function reconciliationProvider(array $fixture, string $suffix): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'reconciliation-'.$suffix,
        'display_name' => 'Reconciliation Provider '.$suffix,
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
        'name' => 'Reconciliation Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://reconciliation/'.$suffix,
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
        'remaining_value' => '10',
        'resets_at' => $now->copy()->addMinute(),
        'dynamically_discovered' => true,
        'discovery_key' => 'reconciliation-'.$suffix,
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

function ambiguousReconciliationOperation(array $fixture, string $suffix): array
{
    $route = reconciliationProvider($fixture, $suffix);
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Reconciliation Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'reconciliation-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Reconciliation '.$suffix],
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
    $recovered = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $leased->id,
        $attemptId,
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unknown,
            requestMayHaveReachedProvider: true,
        ),
    );

    return compact('route', 'leased', 'attemptId', 'recovered');
}

it('resolves accepted reconciliation evidence idempotently without rerouting', function () {
    $fixture = reconciliationTenant('accepted');
    $scenario = ambiguousReconciliationOperation($fixture, 'accepted');
    $evidence = new DeliveryReconciliationEvidence(
        probeAttemptNumber: 1,
        providerAccepted: true,
        reason: 'provider_receipt_confirmed',
    );

    $first = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        $evidence,
    );
    $duplicate = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        $evidence,
    );
    $reconciliation = DB::table('delivery_reconciliations')->where('attempt_id', $scenario['attemptId'])->first();

    expect($first->resolution)->toBe(DeliveryReconciliationResolution::Accepted)
        ->and($first->operation->state)->toBe(DeliveryOperationState::Accepted)
        ->and($first->operation->providerConnectionId)->toBe($scenario['route']['connectionId'])
        ->and($duplicate->changed)->toBeFalse()
        ->and($duplicate->resolution)->toBe(DeliveryReconciliationResolution::Accepted)
        ->and($reconciliation)->not->toBeNull()
        ->and((int) $reconciliation->probe_attempt_number)->toBe(1)
        ->and((bool) $reconciliation->provider_accepted)->toBeTrue()
        ->and(DB::table('audit_events')->where('action', ResolveDeliveryReconciliation::AUDIT_ACTION)->count())->toBe(1);
});

it('keeps retry-safe reconciliation held until explicit compatible failover admission', function () {
    $fixture = reconciliationTenant('failover');
    $scenario = ambiguousReconciliationOperation($fixture, 'failover-source');
    $alternate = reconciliationProvider($fixture, 'failover-alternate');
    $resolved = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        new DeliveryReconciliationEvidence(
            probeAttemptNumber: 1,
            acceptanceKnownNotOccurred: true,
            retrySafe: true,
            reason: 'provider_receipt_absent_retry_safe',
        ),
    );

    $blocked = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $resolved->operation);

    expect($resolved->resolution)->toBe(DeliveryReconciliationResolution::NotAcceptedRetrySafe)
        ->and($resolved->retryAllowed)->toBeTrue()
        ->and($resolved->operation->state)->toBe(DeliveryOperationState::Held)
        ->and($blocked->admitted)->toBeFalse()
        ->and($blocked->backpressureReason)->toBe('operation_state_not_admissible');

    expect(fn () => app(FailoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        $scenario['route']['providerId'],
        $scenario['route']['connectionId'],
    ))->toThrow(RuntimeException::class);

    $failover = app(FailoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        $alternate['providerId'],
        $alternate['connectionId'],
    );

    expect($failover->admitted)->toBeTrue()
        ->and($failover->operation->state)->toBe(DeliveryOperationState::Leased)
        ->and($failover->providerId)->toBe($alternate['providerId'])
        ->and($failover->providerConnectionId)->toBe($alternate['connectionId'])
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('operation_id', $scenario['leased']->id)
            ->where('provider_connection_id', $alternate['connectionId'])
            ->where('attempt_number', 2)
            ->count())->toBe(1)
        ->and(DB::table('delivery_attempts')->where('operation_id', $scenario['leased']->id)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', FailoverDeliveryOperation::AUDIT_ACTION)->count())->toBe(1);
});

it('holds inconclusive reconciliation after its probe budget without manufacturing retry permission', function () {
    config()->set('delivery.recovery.reconciliation_max_probes', 2);
    $fixture = reconciliationTenant('operator');
    $scenario = ambiguousReconciliationOperation($fixture, 'operator');
    $firstEvidence = new DeliveryReconciliationEvidence(
        probeAttemptNumber: 1,
        reason: 'provider_receipt_still_unknown',
    );

    $first = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        $firstEvidence,
    );
    $duplicate = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        $firstEvidence,
    );
    $final = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $scenario['leased']->id,
        $scenario['attemptId'],
        new DeliveryReconciliationEvidence(
            probeAttemptNumber: 2,
            reason: 'provider_receipt_unknown_after_budget',
        ),
    );
    $reconciliation = DB::table('delivery_reconciliations')->where('attempt_id', $scenario['attemptId'])->first();

    expect($first->resolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and($first->operation->state)->toBe(DeliveryOperationState::Reconciling)
        ->and($duplicate->changed)->toBeFalse()
        ->and($final->resolution)->toBe(DeliveryReconciliationResolution::OperatorResolutionRequired)
        ->and($final->operation->state)->toBe(DeliveryOperationState::Held)
        ->and($final->retryAllowed)->toBeFalse()
        ->and($final->operatorActionRequired)->toBeTrue()
        ->and($reconciliation)->not->toBeNull()
        ->and((int) $reconciliation->probe_attempt_number)->toBe(2)
        ->and((bool) $reconciliation->operator_action_required)->toBeTrue();
});
