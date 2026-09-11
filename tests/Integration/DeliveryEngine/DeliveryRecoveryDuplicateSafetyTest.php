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
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function duplicateSafetyTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Duplicate Safety '.$suffix,
        'slug' => 'duplicate-safety-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Duplicate Safety Workspace '.$suffix,
        'slug' => 'duplicate-safety-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Duplicate Safety Brand '.$suffix,
        'slug' => 'duplicate-safety-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'duplicate-safety-'.$suffix,
        ),
    ];
}

function duplicateSafetyProvider(array $fixture, string $suffix): void
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'duplicate-safety-'.$suffix,
        'display_name' => 'Duplicate Safety Provider '.$suffix,
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
        'name' => 'Duplicate Safety Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://duplicate-safety/'.$suffix,
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
        'discovery_key' => 'duplicate-safety-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function duplicateSafetyLeasedOperation(array $fixture, string $suffix)
{
    duplicateSafetyProvider($fixture, $suffix);
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Duplicate Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'duplicate-safety-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Duplicate safety '.$suffix],
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

it('replays accepted durable evidence without creating a second physical attempt', function () {
    $fixture = duplicateSafetyTenant('accepted');
    $operation = duplicateSafetyLeasedOperation($fixture, 'accepted');
    $attemptId = (string) Str::uuid();
    $accepted = new DeliveryFailureObservation(providerAccepted: true);

    $first = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        $attemptId,
        $accepted,
    );
    $replayed = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        $accepted,
    );

    expect($first->operation->state)->toBe(DeliveryOperationState::Accepted)
        ->and($first->action)->toBe(DeliveryRecoveryAction::MarkAccepted)
        ->and($replayed->changed)->toBeFalse()
        ->and($replayed->attemptId)->toBe($attemptId)
        ->and(DB::table('delivery_attempts')->count())->toBe(1)
        ->and(DB::table('delivery_reconciliations')->count())->toBe(0)
        ->and(DB::table('delivery_dead_letters')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', RecoverDeliveryOperation::AUDIT_ACTION)->count())->toBe(1);
});

it('fails closed when a restarted worker reports conflicting evidence for an existing attempt number', function () {
    $fixture = duplicateSafetyTenant('conflict');
    $operation = duplicateSafetyLeasedOperation($fixture, 'conflict');

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
        ->and(DB::table('delivery_attempts')->count())->toBe(1)
        ->and(DB::table('delivery_operations')->where('id', $operation->id)->value('state'))
        ->toBe(DeliveryOperationState::Accepted->value);
});

it('keeps ambiguous transport in durable reconciliation instead of replaying it after expiry', function () {
    $fixture = duplicateSafetyTenant('ambiguous');
    $operation = duplicateSafetyLeasedOperation($fixture, 'ambiguous');

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

    expect($result->operation->state)->toBe(DeliveryOperationState::Reconciling)
        ->and($result->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::AmbiguousTransport)
        ->and($result->reconciliationResolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and(DB::table('delivery_attempts')->count())->toBe(1)
        ->and(DB::table('delivery_reconciliations')->count())->toBe(1)
        ->and(DB::table('delivery_reconciliations')->value('resolution'))
        ->toBe(DeliveryReconciliationResolution::Pending->value)
        ->and(DB::table('delivery_dead_letters')->count())->toBe(0);
});
