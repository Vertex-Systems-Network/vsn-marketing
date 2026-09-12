<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\DescribeDeliveryBackpressure;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
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
function phase04TelemetryCertificationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'PHASE-04 Telemetry '.$suffix,
        'slug' => 'phase04-telemetry-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'PHASE-04 Telemetry Workspace '.$suffix,
        'slug' => 'phase04-telemetry-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'PHASE-04 Telemetry Brand '.$suffix,
        'slug' => 'phase04-telemetry-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'phase04-telemetry-'.$suffix,
        ),
    ];
}

function phase04TelemetryCertificationExhaustedProvider(array $fixture, string $suffix): void
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'phase04-telemetry-'.$suffix,
        'display_name' => 'PHASE-04 Telemetry Provider '.$suffix,
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
        'name' => 'PHASE-04 Telemetry Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://phase04-telemetry/'.$suffix,
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
        'remaining_value' => '0',
        'resets_at' => $now->copy()->addMinute(),
        'dynamically_discovered' => true,
        'discovery_key' => 'phase04-telemetry-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function phase04TelemetryCertificationBackpressuredOperation(array $fixture, string $suffix): DeliveryOperation
{
    phase04TelemetryCertificationExhaustedProvider($fixture, $suffix);
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'PHASE-04 Telemetry '.$suffix);
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($suffix).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        'phase04-telemetry-'.$suffix,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'PHASE-04 Telemetry '.$suffix],
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
    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $queued);

    expect($result->admitted)->toBeFalse()
        ->and($result->backpressureReason)->toBe('quota_exhausted')
        ->and($result->operation->state)->toBe(DeliveryOperationState::Backpressured);

    return $result->operation;
}

it('certifies real quota backpressure emits only bounded operational telemetry dimensions', function () {
    $fixture = phase04TelemetryCertificationTenant('bounded');
    $operation = phase04TelemetryCertificationBackpressuredOperation($fixture, 'bounded');
    $snapshot = app(DescribeDeliveryBackpressure::class)->handle($fixture['context'], $operation);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->operationId)->toBe($operation->id)
        ->and($snapshot?->workspaceId)->toBe($fixture['workspace_id'])
        ->and($snapshot?->channel)->toBe('email')
        ->and($snapshot?->reason)->toBe('quota_exhausted')
        ->and($snapshot?->ageSeconds)->toBeGreaterThanOrEqual(0)
        ->and(array_keys(get_object_vars($snapshot)))->toBe([
            'operationId',
            'workspaceId',
            'providerId',
            'channel',
            'reason',
            'backpressuredAt',
            'ageSeconds',
        ])
        ->and(DB::table('audit_events')
            ->where('workspace_id', $fixture['workspace_id'])
            ->where('action', AdmitDeliveryOperation::BACKPRESSURED_AUDIT_ACTION)
            ->where('subject_id', $operation->id)
            ->count())->toBe(1);
});

it('certifies delivery telemetry omits sensitive execution and recipient identifiers', function () {
    $fixture = phase04TelemetryCertificationTenant('redaction');
    $operation = phase04TelemetryCertificationBackpressuredOperation($fixture, 'redaction');
    $snapshot = app(DescribeDeliveryBackpressure::class)->handle($fixture['context'], $operation);
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain($operation->messageSnapshotId)
        ->and($encoded)->not->toContain($operation->recipientSnapshotId)
        ->and($encoded)->not->toContain($operation->idempotencyKey)
        ->and($encoded)->not->toContain($operation->queuePartitionKey)
        ->and($encoded)->toContain('quota_exhausted');
});

it('certifies cross-workspace telemetry inspection fails closed before evidence is returned', function () {
    $owner = phase04TelemetryCertificationTenant('owner');
    $operation = phase04TelemetryCertificationBackpressuredOperation($owner, 'owner');
    $other = phase04TelemetryCertificationTenant('other');

    expect(fn () => app(DescribeDeliveryBackpressure::class)->handle(
        $other['context'],
        $operation,
    ))->toThrow(AuthorizationException::class, 'Delivery operation access denied.');
});
