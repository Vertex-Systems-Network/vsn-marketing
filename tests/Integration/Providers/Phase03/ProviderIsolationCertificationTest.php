<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Providers\Application\CreateProviderConnection;
use App\Modules\Providers\Application\RecordProviderCapability;
use App\Modules\Providers\Application\RecordProviderQuota;
use App\Modules\Providers\Application\RegisterProvider;
use App\Modules\Providers\Domain\AuthFamily;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// PHASE-03 certification intentionally exercises only workspace-isolation boundaries.
uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run PHASE-03 provider certification tests.');
    }
});

function phase03ProviderTenant(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'PHASE-03 Provider Org '.$suffix,
        'slug' => 'phase03-provider-org-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'PHASE-03 Provider Workspace '.$suffix,
        'slug' => 'phase03-provider-workspace-'.$suffix,
    ]);

    return [
        'workspace' => $workspace,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: null,
            actorId: 'phase03-cert-'.$suffix,
        ),
    ];
}

it('certifies provider connection capability quota and repository reads fail closed across workspaces on the integration database', function () {
    $primary = phase03ProviderTenant('primary');
    $outside = phase03ProviderTenant('outside');

    $provider = app(RegisterProvider::class)->handle(
        $outside['context'],
        'phase03-outside-provider',
        'PHASE-03 Outside Provider',
        'https://example.test/phase03-provider',
    );
    $connection = app(CreateProviderConnection::class)->handle(
        $outside['context'],
        $provider->id,
        'Outside Connection',
        AuthFamily::ApiKey,
        'env://PHASE03_OUTSIDE_PROVIDER_KEY',
        'https://example.test/phase03-provider',
    );

    app(RecordProviderCapability::class)->handle(
        $outside['context'],
        $provider->id,
        'email.send',
        CapabilitySupport::Supported,
        'https://example.test/phase03-provider',
        connectionId: $connection->id,
    );
    app(RecordProviderQuota::class)->handle(
        $outside['context'],
        $provider->id,
        'email.send',
        'account',
        'request',
        'minute',
        'https://example.test/phase03-provider',
        connectionId: $connection->id,
    );

    expect(fn () => app(CreateProviderConnection::class)->handle(
        $primary['context'],
        $provider->id,
        'Denied Connection',
        AuthFamily::ApiKey,
        'env://PHASE03_PRIMARY_KEY',
        'https://example.test/phase03-provider',
    ))->toThrow(AuthorizationException::class, 'Provider access denied.');

    expect(fn () => app(RecordProviderCapability::class)->handle(
        $primary['context'],
        $provider->id,
        'email.send',
        CapabilitySupport::Supported,
        'https://example.test/phase03-provider',
        connectionId: $connection->id,
    ))->toThrow(AuthorizationException::class, 'Provider access denied.');

    expect(fn () => app(RecordProviderQuota::class)->handle(
        $primary['context'],
        $provider->id,
        'email.send',
        'account',
        'request',
        'minute',
        'https://example.test/phase03-provider',
        connectionId: $connection->id,
    ))->toThrow(AuthorizationException::class, 'Provider access denied.');

    $repository = app(ProviderRepository::class);
    $primaryWorkspaceId = (string) $primary['workspace']->getKey();

    expect($repository->findProvider($primaryWorkspaceId, $provider->id))->toBeNull()
        ->and($repository->findConnection($primaryWorkspaceId, $connection->id))->toBeNull()
        ->and(DB::table('provider_connections')->where('workspace_id', $primaryWorkspaceId)->where('provider_id', $provider->id)->count())->toBe(0)
        ->and(DB::table('provider_capabilities')->where('workspace_id', $primaryWorkspaceId)->where('provider_id', $provider->id)->count())->toBe(0)
        ->and(DB::table('provider_quotas')->where('workspace_id', $primaryWorkspaceId)->where('provider_id', $provider->id)->count())->toBe(0);
});
