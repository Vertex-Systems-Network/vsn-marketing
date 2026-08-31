<?php

use App\Modules\Providers\Application\CreateProviderConnection;
use App\Modules\Providers\Application\RecordProviderCapability;
use App\Modules\Providers\Application\RecordProviderQuota;
use App\Modules\Providers\Application\RegisterProvider;
use App\Modules\Providers\Domain\AuthFamily;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed provider tests.');
    }
});

function providerIntegrationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Provider Integration '.$suffix,
        'slug' => 'provider-integration-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Provider Workspace '.$suffix,
        'slug' => 'provider-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: null,
            actorId: 'provider-integration-'.$suffix,
        ),
    ];
}

it('persists provider readiness capability and quota dimensions on PostgreSQL', function () {
    $fixture = providerIntegrationTenant('lifecycle');
    $provider = app(RegisterProvider::class)->handle(
        $fixture['context'],
        'ses',
        'Amazon SES',
        'https://docs.aws.amazon.com/ses/latest/dg/quotas.html',
    );
    $connection = app(CreateProviderConnection::class)->handle(
        $fixture['context'],
        $provider->id,
        'us-east-1',
        AuthFamily::AwsIam,
        'aws-secrets://vsn/ses/us-east-1',
        'https://docs.aws.amazon.com/ses/latest/dg/quotas.html',
        readiness: ProviderReadinessStatus::SandboxOnly,
        region: 'us-east-1',
        accessTier: 'sandbox',
    );
    $capability = app(RecordProviderCapability::class)->handle(
        $fixture['context'],
        $provider->id,
        'email.send',
        CapabilitySupport::Supported,
        'https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html',
        connectionId: $connection->id,
    );
    $quota = app(RecordProviderQuota::class)->handle(
        $fixture['context'],
        $provider->id,
        'email.send',
        'region',
        'recipient',
        'rolling_24h',
        'https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html',
        connectionId: $connection->id,
        scopeReference: 'us-east-1',
        region: 'us-east-1',
        dynamicallyDiscovered: true,
        discoveryKey: 'ses.send-quota',
    );

    expect(DB::table('provider_connections')->where('id', $connection->id)->value('readiness_status'))->toBe('sandbox_only')
        ->and(DB::table('provider_capabilities')->where('id', $capability->id)->value('support_status'))->toBe('supported')
        ->and(DB::table('provider_quotas')->where('id', $quota->id)->value('scope_type'))->toBe('region')
        ->and(DB::table('provider_quotas')->where('id', $quota->id)->value('dynamically_discovered'))->toBeTruthy();
});

it('rejects direct provider child references that cross PostgreSQL workspace boundaries', function () {
    $primary = providerIntegrationTenant('fk-a');
    $outside = providerIntegrationTenant('fk-b');
    $outsideProvider = app(RegisterProvider::class)->handle(
        $outside['context'],
        'outside-pg',
        'Outside PG',
        'https://example.test/outside',
    );
    $now = now();

    expect(fn () => DB::table('provider_connections')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'provider_id' => $outsideProvider->id,
        'name' => 'Invalid',
        'readiness_status' => 'unknown',
        'auth_family' => 'api_key',
        'secret_reference' => 'env://INVALID_PROVIDER_KEY',
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
        'source_url' => 'https://example.test/outside',
        'source_version' => null,
        'observed_at' => $now,
        'fresh_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('provider_capabilities')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'provider_id' => $outsideProvider->id,
        'connection_id' => null,
        'operation' => 'email.send',
        'support_status' => 'unknown',
        'required_scopes' => '[]',
        'required_roles' => '[]',
        'constraints' => '{}',
        'source_url' => 'https://example.test/outside',
        'source_version' => null,
        'observed_at' => $now,
        'fresh_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('provider_quotas')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'provider_id' => $outsideProvider->id,
        'connection_id' => null,
        'operation' => 'email.send',
        'scope_type' => 'account',
        'scope_reference' => null,
        'unit' => 'request',
        'window_type' => 'minute',
        'window_seconds' => 60,
        'region' => null,
        'principal_type' => null,
        'principal_reference' => null,
        'account_tier' => null,
        'limit_value' => null,
        'used_value' => null,
        'remaining_value' => null,
        'resets_at' => null,
        'dynamically_discovered' => false,
        'discovery_key' => null,
        'metadata' => '{}',
        'source_url' => 'https://example.test/outside',
        'source_version' => null,
        'observed_at' => $now,
        'fresh_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});
