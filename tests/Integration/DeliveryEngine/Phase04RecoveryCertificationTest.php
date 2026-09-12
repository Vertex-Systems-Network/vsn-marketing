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
use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0024 recovery certification.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(RedisManager::class)->connection('locks')->flushdb();
});

/** @return array{workspace_id: string, context: TenantContext} */
function phase04RecoveryCertificationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'PHASE-04 Recovery '.$suffix,
        'slug' => 'phase04-recovery-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'PHASE-04 Recovery Workspace '.$suffix,
        'slug' => 'phase04-recovery-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'PHASE-04 Recovery Brand '.$suffix,
        'slug' => 'phase04-recovery-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'phase04-recovery-'.$suffix,
        ),
    ];
}

function phase04RecoveryCertificationProvider(array $fixture, string $suffix): void
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'phase04-recovery-'.$suffix,
        'display_name' => 'PHASE-04 Recovery Provider '.$suffix,
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
        'name' => 'PHASE-04 Recovery Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://phase04-recovery/'.$suffix,
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
        'discovery_key' => 'phase04-recovery-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function phase04RecoveryCertificationLeasedOperation(array $fixture, string $suffix): DeliveryOperation
{
    phase04RecoveryCertificationProvider($fixture, $suffix);
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'PHASE-04 Recovery '.$suffix);
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'phase04-recovery-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'PHASE-04 Recovery '.$suffix],
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

    return app(AdmitDeliveryOperation::class)->handle($fixture['context'], $queued)->operation;
}

it('certifies accepted recovery evidence is durable and duplicate-safe across replay', function () {
    $fixture = phase04RecoveryCertificationTenant('accepted');
    $operation = phase04RecoveryCertificationLeasedOperation($fixture, 'accepted');
    $attemptId = (string) Str::uuid();
    $observation = new DeliveryFailureObservation(providerAccepted: true);

    $first = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        $attemptId,
        $observation,
    );
    $replayed = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        $observation,
    );

    expect($first->operation->state)->toBe(DeliveryOperationState::Accepted)
        ->and($first->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::ProviderAccepted)
        ->and($first->action)->toBe(DeliveryRecoveryAction::MarkAccepted)
        ->and($replayed->changed)->toBeFalse()
        ->and($replayed->attemptId)->toBe($attemptId)
        ->and(DB::table('delivery_attempts')->where('operation_id', $operation->id)->count())->toBe(1)
        ->and(DB::table('delivery_reconciliations')->where('operation_id', $operation->id)->count())->toBe(0)
        ->and(DB::table('delivery_dead_letters')->where('operation_id', $operation->id)->count())->toBe(0)
        ->and(DB::table('audit_events')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('action', RecoverDeliveryOperation::AUDIT_ACTION)
            ->where('subject_id', $operation->id)
            ->count())->toBe(1);
});

it('certifies ambiguous recovery stays held in durable reconciliation until acceptance is proven', function () {
    $fixture = phase04RecoveryCertificationTenant('ambiguous');
    $operation = phase04RecoveryCertificationLeasedOperation($fixture, 'ambiguous');
    $attemptId = (string) Str::uuid();
    $observation = new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Unknown,
        requestMayHaveReachedProvider: true,
    );

    $first = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        $attemptId,
        $observation,
        operationExpired: true,
    );
    $replayed = app(RecoverDeliveryOperation::class)->handle(
        $fixture['context'],
        $operation->id,
        (string) Str::uuid(),
        $observation,
        operationExpired: true,
    );

    expect($first->operation->state)->toBe(DeliveryOperationState::Reconciling)
        ->and($first->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::AmbiguousTransport)
        ->and($first->reconciliationResolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and($replayed->changed)->toBeFalse()
        ->and($replayed->attemptId)->toBe($attemptId)
        ->and(DB::table('delivery_attempts')->where('operation_id', $operation->id)->count())->toBe(1)
        ->and(DB::table('delivery_reconciliations')->where('operation_id', $operation->id)->count())->toBe(1)
        ->and(DB::table('delivery_dead_letters')->where('operation_id', $operation->id)->count())->toBe(0);

    $evidence = new DeliveryReconciliationEvidence(
        providerAccepted: true,
        probeAttemptNumber: 1,
        reason: 'task0024_provider_acceptance_confirmed',
    );
    $resolved = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $operation->id,
        $attemptId,
        $evidence,
    );
    $resolvedReplay = app(ResolveDeliveryReconciliation::class)->handle(
        $fixture['context'],
        $operation->id,
        $attemptId,
        $evidence,
    );

    expect($resolved->resolution)->toBe(DeliveryReconciliationResolution::Accepted)
        ->and($resolved->operation->state)->toBe(DeliveryOperationState::Accepted)
        ->and($resolvedReplay->changed)->toBeFalse()
        ->and($resolvedReplay->resolution)->toBe(DeliveryReconciliationResolution::Accepted)
        ->and(DB::table('delivery_attempts')->where('operation_id', $operation->id)->count())->toBe(1)
        ->and(DB::table('delivery_reconciliations')->where('operation_id', $operation->id)->count())->toBe(1)
        ->and(DB::table('audit_events')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('action', ResolveDeliveryReconciliation::AUDIT_ACTION)
            ->where('subject_id', $operation->id)
            ->count())->toBe(1);
});

it('certifies cross-workspace recovery fails closed without persisting an attempt', function () {
    $owner = phase04RecoveryCertificationTenant('owner');
    $operation = phase04RecoveryCertificationLeasedOperation($owner, 'owner');
    $other = phase04RecoveryCertificationTenant('other');

    expect(fn () => app(RecoverDeliveryOperation::class)->handle(
        $other['context'],
        $operation->id,
        (string) Str::uuid(),
        new DeliveryFailureObservation(providerAccepted: true),
    ))->toThrow(AuthorizationException::class)
        ->and(DB::table('delivery_attempts')->where('operation_id', $operation->id)->count())->toBe(0)
        ->and(DB::table('delivery_reconciliations')->where('operation_id', $operation->id)->count())->toBe(0)
        ->and(DB::table('delivery_operations')->where('id', $operation->id)->value('state'))
        ->toBe(DeliveryOperationState::Leased->value);
});
