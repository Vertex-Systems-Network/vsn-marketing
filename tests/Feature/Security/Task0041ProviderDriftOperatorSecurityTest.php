<?php

use App\Modules\Publishing\Application\Operator\PublishingOperatorReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Publishing\Task0040PublicationFixture;
use Tests\Support\Publishing\Task0040PublicationOperationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(new CarbonImmutable('2026-07-15T13:40:00+00:00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @param array<string, mixed> $fixture */
function task0041ProviderDriftPayload(array $fixture): array
{
    return app(PublishingOperatorReadModel::class)->forWorkspace($fixture['context']);
}

/** @param array<string, mixed> $payload */
function task0041ProviderDriftFirstProvider(array $payload): array
{
    return $payload['campaigns'][0]['publication']['targets'][0]['provider'];
}

it('surfaces a bounded ready provider outcome without leaking sensitive provider evidence', function () {
    $fixture = Task0040PublicationFixture::create('task0041-provider-ready');
    $payload = task0041ProviderDriftPayload($fixture);
    $provider = task0041ProviderDriftFirstProvider($payload);

    expect($provider)->toBe([
        'status' => 'ready',
        'action' => null,
        'retry_blocked' => false,
        'retry_after_seconds' => null,
        'next_probe_at' => null,
        'evidence' => 'canonical_provider_evidence',
    ])->and($payload['summary']['provider_attention'])->toBe(0);

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($encoded)
        ->not->toContain('secret_reference')
        ->not->toContain('vault://')
        ->not->toContain('access_token')
        ->not->toContain('refresh_token')
        ->not->toContain('provider_connection_id')
        ->not->toContain('capability_evidence_id')
        ->not->toContain('granted_scopes')
        ->not->toContain('required_scopes')
        ->not->toContain('source_url')
        ->not->toContain('metadata');
});

it('classifies disconnect credential app-review and permission loss as actionable non-secret outcomes', function () {
    $disconnect = Task0040PublicationFixture::create('task0041-provider-disconnect');
    DB::table('provider_connections')
        ->where('id', $disconnect['providerConnectionId'])
        ->update(['readiness_status' => 'unavailable']);

    expect(task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($disconnect)))
        ->toMatchArray([
            'status' => 'provider_disconnected',
            'action' => 'reconnect_provider',
            'retry_blocked' => true,
        ]);

    $credential = Task0040PublicationFixture::create('task0041-provider-credential');
    DB::table('provider_connections')
        ->where('id', $credential['providerConnectionId'])
        ->update(['readiness_status' => 'auth_required']);

    expect(task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($credential)))
        ->toMatchArray([
            'status' => 'credential_invalid',
            'action' => 'reauthenticate_provider',
            'retry_blocked' => true,
        ]);

    $review = Task0040PublicationFixture::create('task0041-provider-review');
    DB::table('provider_connections')
        ->where('id', $review['providerConnectionId'])
        ->update(['provider_review_status' => 'pending']);

    expect(task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($review)))
        ->toMatchArray([
            'status' => 'app_review_restricted',
            'action' => 'complete_provider_review',
            'retry_blocked' => true,
        ]);

    $permission = Task0040PublicationFixture::create('task0041-provider-permission');
    DB::table('provider_connections')
        ->where('id', $permission['providerConnectionId'])
        ->update(['granted_scopes' => json_encode([], JSON_THROW_ON_ERROR)]);

    expect(task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($permission)))
        ->toMatchArray([
            'status' => 'permission_lost',
            'action' => 'reauthorize_permissions',
            'retry_blocked' => true,
        ]);
});

it('distinguishes stale authority from current capability drift without exposing capability internals', function () {
    $stale = Task0040PublicationFixture::create('task0041-provider-stale');
    DB::table('provider_connections')
        ->where('id', $stale['providerConnectionId'])
        ->update(['fresh_until' => new CarbonImmutable('2026-07-15T13:39:00+00:00')]);

    expect(task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($stale)))
        ->toMatchArray([
            'status' => 'provider_authority_stale',
            'action' => 'refresh_provider_authority',
            'retry_blocked' => true,
        ]);

    $drift = Task0040PublicationFixture::create('task0041-provider-capability-drift');
    Task0040PublicationOperationFixture::addCapability(
        fixture: $drift,
        operation: 'publication.create',
        suffix: 'task0041-new-capability',
        support: 'unsupported',
        observedAt: '2026-07-15T13:35:00+00:00',
        freshUntil: '2026-07-16T13:35:00+00:00',
    );

    $payload = task0041ProviderDriftPayload($drift);
    expect(task0041ProviderDriftFirstProvider($payload))
        ->toMatchArray([
            'status' => 'capability_drift',
            'action' => 'refresh_provider_capability',
            'retry_blocked' => true,
        ])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain($drift['providerCapabilityId']);
});

it('surfaces circuit state before rate limit and exposes only safe recovery timing', function () {
    $fixture = Task0040PublicationFixture::create('task0041-provider-circuit');
    $now = CarbonImmutable::now('UTC');

    DB::table('provider_quotas')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $fixture['context']->workspaceId,
        'provider_id' => $fixture['providerId'],
        'connection_id' => $fixture['providerConnectionId'],
        'operation' => 'publication.create',
        'scope_type' => 'connection',
        'scope_reference' => null,
        'unit' => 'request',
        'window_type' => 'fixed',
        'window_seconds' => 60,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => 10,
        'used_value' => 10,
        'remaining_value' => 0,
        'resets_at' => $now->addSeconds(120),
        'dynamically_discovered' => true,
        'discovery_key' => 'task0041-rate',
        'metadata' => json_encode(['private' => 'do-not-render'], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/private-rate-evidence',
        'source_version' => 'task0041-rate-v1',
        'observed_at' => $now->subSecond(),
        'fresh_until' => $now->addMinutes(5),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('delivery_circuit_breakers')->insert([
        'id' => hash('sha256', 'task0041-provider-circuit'),
        'workspace_id' => $fixture['context']->workspaceId,
        'provider_id' => $fixture['providerId'],
        'provider_connection_id' => $fixture['providerConnectionId'],
        'operation_class' => 'publication.create',
        'state' => 'open',
        'consecutive_failures' => 4,
        'next_probe_at' => $now->addSeconds(60),
        'probe_in_flight' => false,
        'version' => 3,
        'created_at' => $now->subMinutes(2),
        'updated_at' => $now,
    ]);

    $payload = task0041ProviderDriftPayload($fixture);
    $provider = task0041ProviderDriftFirstProvider($payload);

    expect($provider['status'])->toBe('circuit_open')
        ->and($provider['action'])->toBe('wait_for_provider_probe')
        ->and($provider['retry_blocked'])->toBeTrue()
        ->and($provider['next_probe_at'])->not->toBeNull()
        ->and($provider['retry_after_seconds'])->toBeNull()
        ->and($payload['summary']['provider_attention'])->toBe(1);

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($encoded)
        ->not->toContain('consecutive_failures')
        ->not->toContain('private-rate-evidence')
        ->not->toContain('do-not-render');
});

it('surfaces a bounded rate-limit reset when no circuit hold exists', function () {
    $fixture = Task0040PublicationFixture::create('task0041-provider-rate');
    $now = CarbonImmutable::now('UTC');

    DB::table('provider_quotas')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $fixture['context']->workspaceId,
        'provider_id' => $fixture['providerId'],
        'connection_id' => $fixture['providerConnectionId'],
        'operation' => 'publication.create',
        'scope_type' => 'connection',
        'scope_reference' => null,
        'unit' => 'request',
        'window_type' => 'fixed',
        'window_seconds' => 60,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => 10,
        'used_value' => 10,
        'remaining_value' => 0,
        'resets_at' => $now->addSeconds(90),
        'dynamically_discovered' => true,
        'discovery_key' => 'task0041-rate-only',
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'source_url' => 'https://example.test/rate',
        'source_version' => 'task0041-rate-v1',
        'observed_at' => $now->subSecond(),
        'fresh_until' => $now->addMinutes(5),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $provider = task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($fixture));

    expect($provider['status'])->toBe('rate_limited')
        ->and($provider['action'])->toBe('wait_for_rate_reset')
        ->and($provider['retry_blocked'])->toBeTrue()
        ->and($provider['retry_after_seconds'])->toBe(90)
        ->and($provider['next_probe_at'])->toBeNull();
});

it('fails closed when a campaign target references provider evidence from another workspace', function () {
    $local = Task0040PublicationFixture::create('task0041-provider-cross-workspace-local');
    $foreign = Task0040PublicationFixture::create('task0041-provider-cross-workspace-foreign');

    DB::table('campaign_targets')
        ->where('workspace_id', $local['context']->workspaceId)
        ->where('id', $local['target']->id)
        ->update([
            'provider_connection_id' => $foreign['providerConnectionId'],
            'capability_evidence_id' => $foreign['providerCapabilityId'],
        ]);

    $payload = task0041ProviderDriftPayload($local);
    $provider = task0041ProviderDriftFirstProvider($payload);

    expect($provider)->toMatchArray([
        'status' => 'provider_disconnected',
        'action' => 'reconnect_provider',
        'retry_blocked' => true,
    ])->and($payload['summary']['provider_attention'])->toBe(1);

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($encoded)
        ->not->toContain($foreign['providerConnectionId'])
        ->not->toContain($foreign['providerCapabilityId'])
        ->not->toContain('vault://task0040/task0041-provider-cross-workspace-foreign');
});

it('surfaces half-open circuit recovery as an explicit blocked provider outcome', function () {
    $fixture = Task0040PublicationFixture::create('task0041-provider-half-open');
    $now = CarbonImmutable::now('UTC');

    DB::table('delivery_circuit_breakers')->insert([
        'id' => hash('sha256', 'task0041-provider-half-open'),
        'workspace_id' => $fixture['context']->workspaceId,
        'provider_id' => $fixture['providerId'],
        'provider_connection_id' => $fixture['providerConnectionId'],
        'operation_class' => 'publication.create',
        'state' => 'half_open',
        'consecutive_failures' => 2,
        'next_probe_at' => $now->addSeconds(30),
        'probe_in_flight' => true,
        'version' => 4,
        'created_at' => $now->subMinute(),
        'updated_at' => $now,
    ]);

    $provider = task0041ProviderDriftFirstProvider(task0041ProviderDriftPayload($fixture));

    expect($provider['status'])->toBe('circuit_half_open')
        ->and($provider['action'])->toBe('wait_for_provider_probe')
        ->and($provider['retry_blocked'])->toBeTrue()
        ->and($provider['retry_after_seconds'])->toBeNull()
        ->and($provider['next_probe_at'])->not->toBeNull();
});

