<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\DeliveryEngine\Application\AdmitDeliveryOperation;
use App\Modules\DeliveryEngine\Application\CreateDeliveryMessage;
use App\Modules\DeliveryEngine\Application\EnqueueDeliveryOperation;
use App\Modules\DeliveryEngine\Application\MaterializeExecutionSnapshots;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function quotaAdmissionTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Quota '.$suffix,
        'slug' => 'quota-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Quota Workspace '.$suffix,
        'slug' => 'quota-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Quota Brand '.$suffix,
        'slug' => 'quota-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'quota-'.$suffix,
        ),
    ];
}

function quotaAdmissionOperation(array $fixture, string $businessIntentKey, ?DateTimeImmutable $notBefore = null)
{
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Quota Recipient');
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
        ['subject' => 'Quota admission'],
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
        $notBefore,
    );
}

function quotaAdmissionProvider(
    array $fixture,
    string $suffix,
    ?string $remaining,
    string $connectionId,
): array {
    $providerId = (string) Str::uuid();
    $capabilityId = (string) Str::uuid();
    $quotaId = (string) Str::uuid();
    $now = now();

    DB::table('providers')->insert([
        'id' => $providerId,
        'workspace_id' => $fixture['workspace_id'],
        'provider_key' => 'quota-'.$suffix,
        'display_name' => 'Quota Provider '.$suffix,
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
        'name' => 'Connection '.$suffix,
        'readiness_status' => 'ready',
        'auth_family' => 'api_key',
        'secret_reference' => 'secret://quota/'.$suffix,
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

    if ($remaining !== null) {
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
            'discovery_key' => 'quota-'.$suffix,
            'metadata' => '{}',
            'source_url' => 'https://example.test/quota/'.$suffix,
            'source_version' => 'test',
            'observed_at' => $now,
            'fresh_until' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return compact('providerId', 'connectionId', 'quotaId');
}

it('leases a ready operation only after fresh quota evidence is atomically consumed', function () {
    $fixture = quotaAdmissionTenant('admit');
    $route = quotaAdmissionProvider(
        $fixture,
        'admit',
        '2',
        '00000000-0000-4000-8000-000000000001',
    );
    $operation = quotaAdmissionOperation($fixture, 'quota-admit-1');

    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);
    $again = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);

    expect($result->admitted)->toBeTrue()
        ->and($result->changed)->toBeTrue()
        ->and($result->operation->state)->toBe(DeliveryOperationState::Leased)
        ->and($result->providerId)->toBe($route['providerId'])
        ->and($result->providerConnectionId)->toBe($route['connectionId'])
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(1)
        ->and($again->admitted)->toBeTrue()
        ->and($again->changed)->toBeFalse()
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AdmitDeliveryOperation::ADMITTED_AUDIT_ACTION)->count())->toBe(1);
});

it('backpressures instead of exceeding the remaining quota budget', function () {
    $fixture = quotaAdmissionTenant('exhausted');
    quotaAdmissionProvider(
        $fixture,
        'exhausted',
        '1',
        '00000000-0000-4000-8000-000000000001',
    );
    $first = quotaAdmissionOperation($fixture, 'quota-exhausted-1');
    $second = quotaAdmissionOperation($fixture, 'quota-exhausted-2');

    $accepted = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $first);
    $blocked = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $second);
    $firstBackpressuredAt = $blocked->operation->backpressuredAt?->getTimestamp();
    $blockedAgain = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $second);

    expect($accepted->admitted)->toBeTrue()
        ->and($blocked->admitted)->toBeFalse()
        ->and($blocked->backpressureReason)->toBe('quota_exhausted')
        ->and($blocked->operation->state)->toBe(DeliveryOperationState::Backpressured)
        ->and($blockedAgain->changed)->toBeFalse()
        ->and($blockedAgain->operation->backpressuredAt?->getTimestamp())->toBe($firstBackpressuredAt)
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AdmitDeliveryOperation::BACKPRESSURED_AUDIT_ACTION)->count())->toBe(1);
});

it('fails closed to observable backpressure when quota evidence is missing', function () {
    $fixture = quotaAdmissionTenant('missing');
    quotaAdmissionProvider(
        $fixture,
        'missing',
        null,
        '00000000-0000-4000-8000-000000000001',
    );
    $operation = quotaAdmissionOperation($fixture, 'quota-missing-1');

    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);

    expect($result->admitted)->toBeFalse()
        ->and($result->backpressureReason)->toBe('quota_evidence_missing')
        ->and($result->operation->state)->toBe(DeliveryOperationState::Backpressured)
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(0);
});

it('does not turn scheduled not-before time into capacity backpressure', function () {
    $fixture = quotaAdmissionTenant('scheduled');
    quotaAdmissionProvider(
        $fixture,
        'scheduled',
        '10',
        '00000000-0000-4000-8000-000000000001',
    );
    $operation = quotaAdmissionOperation(
        $fixture,
        'quota-scheduled-1',
        now()->addHour()->toDateTimeImmutable(),
    );

    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);

    expect($result->admitted)->toBeFalse()
        ->and($result->changed)->toBeFalse()
        ->and($result->backpressureReason)->toBe('scheduled_not_before')
        ->and($result->operation->state)->toBe(DeliveryOperationState::Scheduled)
        ->and(DB::table('audit_events')->whereIn('action', [
            AdmitDeliveryOperation::ADMITTED_AUDIT_ACTION,
            AdmitDeliveryOperation::BACKPRESSURED_AUDIT_ACTION,
        ])->count())->toBe(0);
});

it('selects the next deterministic eligible connection when an earlier route is exhausted', function () {
    $fixture = quotaAdmissionTenant('fallback');
    quotaAdmissionProvider(
        $fixture,
        'first',
        '0',
        '00000000-0000-4000-8000-000000000001',
    );
    $secondRoute = quotaAdmissionProvider(
        $fixture,
        'second',
        '3',
        '00000000-0000-4000-8000-000000000002',
    );
    $operation = quotaAdmissionOperation($fixture, 'quota-fallback-1');

    $result = app(AdmitDeliveryOperation::class)->handle($fixture['context'], $operation);

    expect($result->admitted)->toBeTrue()
        ->and($result->providerConnectionId)->toBe($secondRoute['connectionId'])
        ->and(DB::table('delivery_operation_quota_consumptions')
            ->where('provider_connection_id', $secondRoute['connectionId'])
            ->count())->toBe(1);
});

it('rejects admission through a context from another workspace', function () {
    $inside = quotaAdmissionTenant('context-inside');
    $outside = quotaAdmissionTenant('context-outside');
    $operation = quotaAdmissionOperation($inside, 'quota-context-1');

    expect(fn () => app(AdmitDeliveryOperation::class)->handle($outside['context'], $operation))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('delivery_operation_quota_consumptions')->count())->toBe(0);
});
