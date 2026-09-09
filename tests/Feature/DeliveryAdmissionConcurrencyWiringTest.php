<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionCoordinator;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

final class RecordingDeliveryAdmissionCoordinator implements DeliveryAdmissionCoordinator
{
    /** @var list<array<string, int|string>> */
    public array $acquisitions = [];

    /** @var list<array<string, string>> */
    public array $releases = [];

    public function __construct(private bool $result) {}

    public function tryAcquire(
        string $workspaceId,
        string $operationId,
        int $workspaceConcurrencyLimit,
        int $globalConcurrencyLimit,
        int $ttlSeconds,
    ): bool {
        $this->acquisitions[] = [
            'workspace_id' => $workspaceId,
            'operation_id' => $operationId,
            'workspace_limit' => $workspaceConcurrencyLimit,
            'global_limit' => $globalConcurrencyLimit,
            'ttl_seconds' => $ttlSeconds,
        ];

        return $this->result;
    }

    public function release(string $workspaceId, string $operationId): void
    {
        $this->releases[] = [
            'workspace_id' => $workspaceId,
            'operation_id' => $operationId,
        ];
    }
}

function coordinatedAdmissionTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Coordinated '.$suffix,
        'slug' => 'coordinated-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Coordinated Workspace '.$suffix,
        'slug' => 'coordinated-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Coordinated Brand '.$suffix,
        'slug' => 'coordinated-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'coordinated-'.$suffix,
        ),
    ];
}

function coordinatedAdmissionOperation(array $fixture, string $businessIntentKey)
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Coordinated Recipient');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        strtolower($businessIntentKey).'@example.test',
    );
    $message = app(CreateDeliveryMessage::class)->handle(
        $fixture['context'],
        $businessIntentKey,
        MessageIntentType::Transactional,
        DeliveryChannel::Email,
        ['subject' => 'Coordinated admission'],
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

function coordinatedAdmissionProvider(array $fixture, string $suffix): void
{
    $providerId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();
    $capabilityId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'coordinated-'.$suffix,
        'display_name' => 'Coordinated Provider '.$suffix,
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
        'name' => 'Coordinated Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://coordinated/'.$suffix,
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
        'id' => $capabilityId,
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
        'discovery_key' => 'coordinated-'.$suffix,
        'metadata' => '{}',
        'source_url' => 'https://example.test/quota/'.$suffix,
        'source_version' => 'test',
        'observed_at' => $now,
        'fresh_until' => $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('backpressures without consuming provider quota when Redis capacity is unavailable', function () {
    config()->set('delivery.admission.concurrency_enabled', true);
    config()->set('delivery.admission.global_concurrency_limit', 4);
    config()->set('delivery.admission.workspace_concurrency_limit', null);
    config()->set('delivery.admission.reservation_ttl_seconds', 45);

    $coordinator = new RecordingDeliveryAdmissionCoordinator(false);
    app()->instance(DeliveryAdmissionCoordinator::class, $coordinator);
    app()->forgetInstance(DeliveryAdmissionRepository::class);

    $fixture = coordinatedAdmissionTenant('capacity-blocked');
    coordinatedAdmissionProvider($fixture, 'capacity-blocked');
    $operation = coordinatedAdmissionOperation($fixture, 'capacity-blocked-operation');

    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);

    expect($result->admitted)->toBeFalse()
        ->and($result->backpressureReason)->toBe('concurrency_capacity_exhausted')
        ->and($result->operation->state)->toBe(DeliveryOperationState::Backpressured)
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(0)
        ->and($coordinator->acquisitions)->toHaveCount(1)
        ->and($coordinator->acquisitions[0]['workspace_limit'])->toBe(4)
        ->and($coordinator->acquisitions[0]['global_limit'])->toBe(4)
        ->and($coordinator->acquisitions[0]['ttl_seconds'])->toBe(45);
});

it('derives an equal fair share from currently eligible workspace demand before Redis admission', function () {
    config()->set('delivery.admission.concurrency_enabled', true);
    config()->set('delivery.admission.global_concurrency_limit', 4);
    config()->set('delivery.admission.workspace_concurrency_limit', null);
    config()->set('delivery.admission.reservation_ttl_seconds', 30);

    $coordinator = new RecordingDeliveryAdmissionCoordinator(true);
    app()->instance(DeliveryAdmissionCoordinator::class, $coordinator);
    app()->forgetInstance(DeliveryAdmissionRepository::class);

    $first = coordinatedAdmissionTenant('fairness-a');
    $second = coordinatedAdmissionTenant('fairness-b');
    coordinatedAdmissionProvider($first, 'fairness-a');
    $firstOperation = coordinatedAdmissionOperation($first, 'fairness-a-operation');
    coordinatedAdmissionOperation($second, 'fairness-b-operation');

    $result = app(AdmitDeliveryOperation::class)->handle($first['context'], $firstOperation);

    expect($result->admitted)->toBeTrue()
        ->and($result->operation->state)->toBe(DeliveryOperationState::Leased)
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(1)
        ->and($coordinator->acquisitions)->toHaveCount(1)
        ->and($coordinator->acquisitions[0]['workspace_limit'])->toBe(2)
        ->and($coordinator->acquisitions[0]['global_limit'])->toBe(4);
});
