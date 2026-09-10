<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterReason;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function recoveryTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Recovery '.$suffix,
        'slug' => 'recovery-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Recovery Workspace '.$suffix,
        'slug' => 'recovery-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Recovery Brand '.$suffix,
        'slug' => 'recovery-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'recovery-'.$suffix,
        ),
    ];
}

function recoveryProvider(array $fixture, string $suffix): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'recovery-'.$suffix,
        'display_name' => 'Recovery Provider '.$suffix,
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
        'name' => 'Recovery Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://recovery/'.$suffix,
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
        'discovery_key' => 'recovery-'.$suffix,
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

function leasedRecoveryOperation(array $fixture, string $suffix)
{
    recoveryProvider($fixture, $suffix);
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Recovery Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'recovery-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Recovery '.$suffix],
    );
    $snapshots = app(MaterializeExecutionSnapshots::class)->handle(
        $fixture['context'],
        $message->id,
        $contact->id,
        $identity->id,
    );
    $operation = app(EnqueueDeliveryOperation::class)->handle(
        $fixture['context'],
        $snapshots->message->id,
        $snapshots->recipient->id,
    );

    return app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation)->operation;
}

it('schedules a proven pre-accept transient retry and persists breaker evidence', function () {
    $fixture = recoveryTenant('transient');
    $operation = leasedRecoveryOperation($fixture, 'transient');

    $result = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Retryable,
            acceptanceKnownNotOccurred: true,
            attemptNumber: 1,
            maxAttempts: 3,
        ),
    );

    $attempt = DB::table('delivery_attempts')->first();
    $breaker = DB::table('delivery_circuit_breakers')->first();

    expect($result->changed)->toBeTrue()
        ->and($result->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::TransientPreAccept)
        ->and($result->action)->toBe(DeliveryRecoveryAction::RetrySameRoute)
        ->and($result->operation->state)->toBe(DeliveryOperationState::Scheduled)
        ->and($result->operation->providerConnectionId)->toBe($operation->providerConnectionId)
        ->and($result->nextAttemptAt)->not->toBeNull()
        ->and($attempt)->not->toBeNull()
        ->and($attempt->operation_state_after)->toBe(DeliveryOperationState::Scheduled->value)
        ->and($breaker)->not->toBeNull()
        ->and((int) $breaker->consecutive_failures)->toBe(1)
        ->and($breaker->state)->toBe('closed')
        ->and(DB::table('audit_events')->where('action', RecoverDeliveryOperation::AUDIT_ACTION)->count())->toBe(1);
});

it('marks accepted provider evidence monotonically and replays duplicate handling from the ledger', function () {
    $fixture = recoveryTenant('accepted');
    $operation = leasedRecoveryOperation($fixture, 'accepted');
    $firstAttemptId = (string) Str::uuid();
    $observation = new DeliveryFailureObservation(providerAccepted: true);

    $first = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        $firstAttemptId,
        $observation,
    );
    $duplicate = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        $observation,
    );

    expect($first->operation->state)->toBe(DeliveryOperationState::Accepted)
        ->and($first->action)->toBe(DeliveryRecoveryAction::MarkAccepted)
        ->and($duplicate->changed)->toBeFalse()
        ->and($duplicate->attemptId)->toBe($firstAttemptId)
        ->and(DB::table('delivery_attempts')->count())->toBe(1)
        ->and(DB::table('delivery_dead_letters')->count())->toBe(0)
        ->and(DB::table('delivery_reconciliations')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', RecoverDeliveryOperation::AUDIT_ACTION)->count())->toBe(1);
});

it('fails closed when duplicate attempt numbers carry conflicting evidence', function () {
    $fixture = recoveryTenant('conflict');
    $operation = leasedRecoveryOperation($fixture, 'conflict');

    app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(providerAccepted: true),
    );

    expect(fn () => app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unknown,
            requestMayHaveReachedProvider: true,
        ),
    ))->toThrow(RuntimeException::class)
        ->and(DB::table('delivery_attempts')->count())->toBe(1);
});

it('keeps ambiguous transport in reconciliation even when expiry is also reported', function () {
    $fixture = recoveryTenant('ambiguous');
    $operation = leasedRecoveryOperation($fixture, 'ambiguous');

    $result = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unknown,
            requestMayHaveReachedProvider: true,
        ),
        operationExpired: true,
    );
    $reconciliation = DB::table('delivery_reconciliations')->first();

    expect($result->operation->state)->toBe(DeliveryOperationState::Reconciling)
        ->and($result->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::AmbiguousTransport)
        ->and($result->reconciliationResolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and($reconciliation)->not->toBeNull()
        ->and($reconciliation->resolution)->toBe(DeliveryReconciliationResolution::Pending->value)
        ->and(DB::table('delivery_dead_letters')->count())->toBe(0);
});

it('dead letters permanent validation evidence with an auditable terminal reason', function () {
    $fixture = recoveryTenant('permanent');
    $operation = leasedRecoveryOperation($fixture, 'permanent');

    $result = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(errorCategory: ProviderErrorCategory::Validation),
    );
    $deadLetter = DB::table('delivery_dead_letters')->first();

    expect($result->operation->state)->toBe(DeliveryOperationState::DeadLettered)
        ->and($result->deadLetterReason)->toBe(DeliveryDeadLetterReason::PermanentFailure)
        ->and($deadLetter)->not->toBeNull()
        ->and($deadLetter->reason)->toBe(DeliveryDeadLetterReason::PermanentFailure->value)
        ->and($deadLetter->audit_reason)->toBe('permanent_failure_terminal_evidence');
});

it('persists a shared breaker failure streak across sequential attempts on the same route', function () {
    config()->set('delivery.recovery.breaker_failure_threshold', 3);
    $fixture = recoveryTenant('breaker');
    $operation = leasedRecoveryOperation($fixture, 'breaker');

    foreach ([1, 2, 3] as $attemptNumber) {
        app(RecoverDeliveryOperation::class)->handle(
            $fixture['context'],
            $operation->id,
            (string) Str::uuid(),
            new DeliveryFailureObservation(
                errorCategory: ProviderErrorCategory::Unavailable,
                httpStatus: 503,
                acceptanceKnownNotOccurred: true,
                attemptNumber: $attemptNumber,
                maxAttempts: 4,
            ),
        );

        if ($attemptNumber < 3) {
            DB::table('delivery_operations')
                ->where('id', $operation->id)
                ->where('workspace_id', $fixture['workspace_id'])
                ->update([
                    'state' => DeliveryOperationState::Leased->value,
                    'scheduled_not_before_at' => now()->subSecond(),
                ]);
        }
    }

    $breaker = DB::table('delivery_circuit_breakers')->first();

    expect(DB::table('delivery_attempts')->count())->toBe(3)
        ->and($breaker)->not->toBeNull()
        ->and((int) $breaker->consecutive_failures)->toBe(3)
        ->and($breaker->state)->toBe('open')
        ->and($breaker->next_probe_at)->not->toBeNull();
});

it('denies recovery reads across workspace boundaries', function () {
    $inside = recoveryTenant('inside');
    $outside = recoveryTenant('outside');
    $operation = leasedRecoveryOperation($inside, 'inside');

    expect(fn () => app(RecoverDeliveryOperation::class)->handle(
        $outside['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(providerAccepted: true),
    ))->toThrow(AuthorizationException::class)
        ->and(DB::table('delivery_attempts')->count())->toBe(0);
});
