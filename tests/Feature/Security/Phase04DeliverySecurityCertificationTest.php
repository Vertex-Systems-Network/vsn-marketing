<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\DescribeDeliveryBackpressure;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Application\RecoverDeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['delivery.admission.concurrency_enabled' => false]);
});

/** @return array{workspace_id: string, context: TenantContext} */
function phase04SecurityCertificationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'PHASE-04 Security '.$suffix,
        'slug' => 'phase04-security-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'PHASE-04 Security Workspace '.$suffix,
        'slug' => 'phase04-security-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'PHASE-04 Security Brand '.$suffix,
        'slug' => 'phase04-security-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'phase04-security-'.$suffix,
        ),
    ];
}

/** @return array{provider_id: string, connection_id: string, secret_marker: string} */
function phase04SecurityCertificationProvider(array $fixture, string $suffix, string $remaining): array
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $secretMarker = 'phase04-security-secret-'.$suffix;
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'phase04-security-'.$suffix,
        'display_name' => 'PHASE-04 Security Provider '.$suffix,
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
        'name' => 'PHASE-04 Security Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://'.$secretMarker,
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
        'remaining_value' => $remaining,
        'resets_at' => $now->copy()->addMinute(),
        'dynamically_discovered' => true,
        'discovery_key' => 'phase04-security-'.$suffix,
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
        'secret_marker' => $secretMarker,
    ];
}

/** @return array{operation: DeliveryOperation, recipient_marker: string, subject_marker: string} */
function phase04SecurityCertificationOperation(array $fixture, string $suffix): array
{
    $recipientMarker = 'phase04-security-recipient-'.$suffix.'@example.test';
    $subjectMarker = 'PHASE-04 security subject '.$suffix;
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'PHASE-04 Security '.$suffix);
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        $recipientMarker,
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'phase04-security-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => $subjectMarker],
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

    return [
        'operation' => $operation,
        'recipient_marker' => $recipientMarker,
        'subject_marker' => $subjectMarker,
    ];
}

it('certifies cross-workspace admission fails closed without mutating or auditing the operation', function () {
    $owner = phase04SecurityCertificationTenant('admission-owner');
    $scenario = phase04SecurityCertificationOperation($owner, 'admission-owner');
    $other = phase04SecurityCertificationTenant('admission-other');
    $operation = $scenario['operation'];

    expect(fn () => app(AdmitDeliveryOperation::class)->handle(
        $other['context'],
        $operation,
    ))->toThrow(AuthorizationException::class, 'Delivery operation access denied.');

    expect(DB::table('delivery_operations')->where('id', $operation->id)->value('state'))
        ->toBe($operation->state->value)
        ->and(DB::table('delivery_attempts')->where('operation_id', $operation->id)->count())->toBe(0)
        ->and(DB::table('audit_events')
            ->where('workspace_id', $other['workspace_id'])
            ->whereIn('action', [
                AdmitDeliveryOperation::ADMITTED_AUDIT_ACTION,
                AdmitDeliveryOperation::BACKPRESSURED_AUDIT_ACTION,
            ])
            ->where('subject_id', $operation->id)
            ->count())->toBe(0);
});

it('certifies cross-workspace recovery cannot create physical attempts or recovery audit evidence', function () {
    $owner = phase04SecurityCertificationTenant('recovery-owner');
    phase04SecurityCertificationProvider($owner, 'recovery-owner', '10');
    $scenario = phase04SecurityCertificationOperation($owner, 'recovery-owner');
    $operation = app(AdmitDeliveryOperation::class)->handle($owner['context'], $scenario['operation'])->operation;
    $other = phase04SecurityCertificationTenant('recovery-other');

    expect($operation->state)->toBe(DeliveryOperationState::Leased)
        ->and(fn () => app(RecoverDeliveryOperation::class)->handle(
            $other['context'],
            $operation->id,
            (string) Str::uuid(),
            new DeliveryFailureObservation(providerAccepted: true),
        ))->toThrow(AuthorizationException::class, 'Delivery recovery access denied.');

    expect(DB::table('delivery_attempts')->where('operation_id', $operation->id)->count())->toBe(0)
        ->and(DB::table('audit_events')
            ->where('workspace_id', $other['workspace_id'])
            ->where('action', RecoverDeliveryOperation::AUDIT_ACTION)
            ->where('subject_id', $operation->id)
            ->count())->toBe(0)
        ->and(DB::table('delivery_operations')->where('id', $operation->id)->value('state'))
        ->toBe(DeliveryOperationState::Leased->value);
});

it('certifies backpressure audit evidence is bounded and excludes secret delivery material', function () {
    $fixture = phase04SecurityCertificationTenant('audit-redaction');
    $provider = phase04SecurityCertificationProvider($fixture, 'audit-redaction', '0');
    $scenario = phase04SecurityCertificationOperation($fixture, 'audit-redaction');
    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $scenario['operation']);
    $operation = $result->operation;
    $audit = DB::table('audit_events')
        ->where('workspace_id', $fixture['workspace_id'])
        ->where('action', AdmitDeliveryOperation::BACKPRESSURED_AUDIT_ACTION)
        ->where('subject_id', $operation->id)
        ->first();

    expect($result->admitted)->toBeFalse()
        ->and($result->backpressureReason)->toBe('quota_exhausted')
        ->and($audit)->not->toBeNull();

    $evidence = json_decode((string) $audit->evidence, true, 512, JSON_THROW_ON_ERROR);
    $encoded = json_encode($evidence, JSON_THROW_ON_ERROR);

    expect(array_keys($evidence))->toBe([
        'state',
        'provider_id',
        'provider_connection_id',
        'backpressure_reason',
        'version',
    ])
        ->and($evidence['state'])->toBe(DeliveryOperationState::Backpressured->value)
        ->and($evidence['backpressure_reason'])->toBe('quota_exhausted')
        ->and($encoded)->not->toContain($provider['secret_marker'])
        ->and($encoded)->not->toContain($scenario['recipient_marker'])
        ->and($encoded)->not->toContain($scenario['subject_marker'])
        ->and($encoded)->not->toContain($operation->messageSnapshotId)
        ->and($encoded)->not->toContain($operation->recipientSnapshotId)
        ->and($encoded)->not->toContain($operation->idempotencyKey)
        ->and($encoded)->not->toContain($operation->queuePartitionKey);
});

it('certifies operational telemetry remains redacted and workspace scoped for real backpressure', function () {
    $owner = phase04SecurityCertificationTenant('telemetry-owner');
    $provider = phase04SecurityCertificationProvider($owner, 'telemetry-owner', '0');
    $scenario = phase04SecurityCertificationOperation($owner, 'telemetry-owner');
    $operation = app(AdmitDeliveryOperation::class)->handle($owner['context'], $scenario['operation'])->operation;
    $snapshot = app(DescribeDeliveryBackpressure::class)->handle($owner['context'], $operation);
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $other = phase04SecurityCertificationTenant('telemetry-other');

    expect(array_keys(get_object_vars($snapshot)))->toBe([
        'operationId',
        'workspaceId',
        'providerId',
        'channel',
        'reason',
        'backpressuredAt',
        'ageSeconds',
    ])
        ->and($snapshot?->reason)->toBe('quota_exhausted')
        ->and($encoded)->not->toContain($provider['secret_marker'])
        ->and($encoded)->not->toContain($scenario['recipient_marker'])
        ->and($encoded)->not->toContain($scenario['subject_marker'])
        ->and($encoded)->not->toContain($operation->messageSnapshotId)
        ->and($encoded)->not->toContain($operation->recipientSnapshotId)
        ->and($encoded)->not->toContain($operation->idempotencyKey)
        ->and($encoded)->not->toContain($operation->queuePartitionKey)
        ->and(fn () => app(DescribeDeliveryBackpressure::class)->handle(
            $other['context'],
            $operation,
        ))->toThrow(AuthorizationException::class, 'Delivery operation access denied.');
});
