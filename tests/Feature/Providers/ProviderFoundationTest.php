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
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function providerTenantFixture(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Provider Org '.$suffix,
        'slug' => 'provider-org-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Provider Workspace '.$suffix,
        'slug' => 'provider-workspace-'.$suffix,
    ]);

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: null,
            actorId: 'provider-test-'.$suffix,
        ),
    ];
}

it('keeps capability support separate from connection readiness and stores only a secret reference', function () {
    $fixture = providerTenantFixture('separation');
    $provider = app(RegisterProvider::class)->handle(
        $fixture['context'],
        'brevo',
        'Brevo',
        'https://developers.brevo.com/docs/oauth',
        category: 'marketing_platform',
        sourceVersion: '2026-08',
    );
    $connection = app(CreateProviderConnection::class)->handle(
        $fixture['context'],
        $provider->id,
        'Primary',
        AuthFamily::OAuth2,
        'env://BREVO_OAUTH_SECRET',
        'https://developers.brevo.com/docs/oauth',
        readiness: ProviderReadinessStatus::PrivateOnly,
        requestedScopes: ['companies', 'contacts'],
        grantedScopes: ['companies'],
        accessTier: 'private_app',
        providerReviewStatus: 'private_only',
        refreshSupported: true,
    );
    $capability = app(RecordProviderCapability::class)->handle(
        $fixture['context'],
        $provider->id,
        'email.send',
        CapabilitySupport::Supported,
        'https://developers.brevo.com/reference/sendtransacemail',
        connectionId: $connection->id,
        requiredScopes: ['transactionalEmail'],
    );

    expect($connection->readiness)->toBe(ProviderReadinessStatus::PrivateOnly)
        ->and($capability->support)->toBe(CapabilitySupport::Supported);

    $stored = DB::table('provider_connections')->where('id', $connection->id)->first();
    expect($stored)->not->toBeNull()
        ->and($stored->secret_reference)->toBe('env://BREVO_OAUTH_SECRET');

    $auditJson = DB::table('audit_events')->where('subject_id', $connection->id)->value('evidence');
    expect((string) $auditJson)->not->toContain('BREVO_OAUTH_SECRET');
});

it('fails closed when capability support is unknown even if a connection is ready', function () {
    $fixture = providerTenantFixture('unknown');
    $provider = app(RegisterProvider::class)->handle(
        $fixture['context'],
        'future-provider',
        'Future Provider',
        'https://example.test/provider-docs',
    );
    $connection = app(CreateProviderConnection::class)->handle(
        $fixture['context'],
        $provider->id,
        'Ready Connection',
        AuthFamily::ApiKey,
        'secret://providers/future/api-key',
        'https://example.test/provider-docs',
        readiness: ProviderReadinessStatus::Ready,
    );
    $capability = app(RecordProviderCapability::class)->handle(
        $fixture['context'],
        $provider->id,
        'messages.send',
        CapabilitySupport::Unknown,
        'https://example.test/provider-docs',
        connectionId: $connection->id,
    );

    expect($connection->readiness)->toBe(ProviderReadinessStatus::Ready)
        ->and($capability->support)->toBe(CapabilitySupport::Unknown)
        ->and($capability->support)->not->toBe(CapabilitySupport::Supported);
});

it('stores multiple concurrent quota dimensions without hard-coded provider defaults', function () {
    $fixture = providerTenantFixture('quota');
    $provider = app(RegisterProvider::class)->handle(
        $fixture['context'],
        'gmail',
        'Gmail API',
        'https://developers.google.com/workspace/gmail/api/reference/quota',
    );
    $connection = app(CreateProviderConnection::class)->handle(
        $fixture['context'],
        $provider->id,
        'Mailbox',
        AuthFamily::OAuth2,
        'vault://providers/gmail/workspace-oauth',
        'https://developers.google.com/workspace/gmail/api/auth/scopes',
        readiness: ProviderReadinessStatus::Ready,
        principalType: 'user',
        principalReference: 'user-42',
    );

    $projectQuota = app(RecordProviderQuota::class)->handle(
        $fixture['context'],
        $provider->id,
        'messages.send',
        'project',
        'quota_unit',
        'minute',
        'https://developers.google.com/workspace/gmail/api/reference/quota',
        connectionId: $connection->id,
        scopeReference: 'project-7',
        windowSeconds: 60,
        limitValue: '1200000',
        dynamicallyDiscovered: false,
        sourceVersion: '2026-05-01',
    );
    $userQuota = app(RecordProviderQuota::class)->handle(
        $fixture['context'],
        $provider->id,
        'messages.send',
        'user',
        'quota_unit',
        'minute',
        'https://developers.google.com/workspace/gmail/api/reference/quota',
        connectionId: $connection->id,
        scopeReference: 'user-42',
        windowSeconds: 60,
        remainingValue: '14000',
        dynamicallyDiscovered: true,
        discoveryKey: 'gmail.quota.user',
        sourceVersion: '2026-05-01',
    );

    expect($projectQuota->scopeType)->toBe('project')
        ->and($userQuota->scopeType)->toBe('user')
        ->and($userQuota->dynamicallyDiscovered)->toBeTrue()
        ->and(DB::table('provider_quotas')->where('provider_id', $provider->id)->count())->toBe(2);
});

it('rejects raw credential material instead of canonicalizing it', function () {
    $fixture = providerTenantFixture('raw-secret');
    $provider = app(RegisterProvider::class)->handle(
        $fixture['context'],
        'secret-test',
        'Secret Test',
        'https://example.test/provider-docs',
    );

    expect(fn () => app(CreateProviderConnection::class)->handle(
        $fixture['context'],
        $provider->id,
        'Unsafe',
        AuthFamily::ApiKey,
        'xkeysib-this-is-raw-secret-material',
        'https://example.test/provider-docs',
    ))->toThrow(InvalidArgumentException::class, 'Provider credentials must be stored as an approved secret reference.');
});

it('fails closed across workspace provider connection capability quota and secret-reference reads', function () {
    $primary = providerTenantFixture('scope-a');
    $outside = providerTenantFixture('scope-b');
    $outsideProvider = app(RegisterProvider::class)->handle(
        $outside['context'],
        'outside',
        'Outside Provider',
        'https://example.test/outside-docs',
    );
    $outsideConnection = app(CreateProviderConnection::class)->handle(
        $outside['context'],
        $outsideProvider->id,
        'Outside Connection',
        AuthFamily::ApiKey,
        'env://OUTSIDE_PROVIDER_KEY',
        'https://example.test/outside-docs',
    );

    expect(fn () => app(CreateProviderConnection::class)->handle(
        $primary['context'],
        $outsideProvider->id,
        'Denied',
        AuthFamily::ApiKey,
        'env://PRIMARY_KEY',
        'https://example.test/outside-docs',
    ))->toThrow(AuthorizationException::class, 'Provider access denied.');

    expect(fn () => app(RecordProviderCapability::class)->handle(
        $primary['context'],
        $outsideProvider->id,
        'email.send',
        CapabilitySupport::Supported,
        'https://example.test/outside-docs',
        connectionId: $outsideConnection->id,
    ))->toThrow(AuthorizationException::class, 'Provider access denied.');

    expect(fn () => app(RecordProviderQuota::class)->handle(
        $primary['context'],
        $outsideProvider->id,
        'email.send',
        'account',
        'request',
        'minute',
        'https://example.test/outside-docs',
        connectionId: $outsideConnection->id,
    ))->toThrow(AuthorizationException::class, 'Provider access denied.');

    $repository = app(ProviderRepository::class);
    expect($repository->findProvider($primary['workspace']->getKey(), $outsideProvider->id))->toBeNull()
        ->and($repository->findConnection($primary['workspace']->getKey(), $outsideConnection->id))->toBeNull();
});
