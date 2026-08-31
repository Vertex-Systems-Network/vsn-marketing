<?php

namespace App\Modules\Providers\Infrastructure;

use App\Modules\Providers\Domain\AuthFamily;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\Provider;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\ProviderConnection;
use App\Modules\Providers\Domain\ProviderQuota;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Domain\SecretReference;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use JsonException;
use stdClass;

final readonly class DatabaseProviderRepository implements ProviderRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function saveProvider(Provider $provider): void
    {
        $this->database->connection()->table('providers')->insert([
            'id' => $provider->id,
            'workspace_id' => $provider->workspaceId,
            'provider_key' => $provider->key,
            'display_name' => $provider->displayName,
            'category' => $provider->category,
            'metadata' => $this->encode($provider->metadata),
            'source_url' => $provider->sourceUrl,
            'source_version' => $provider->sourceVersion,
            'observed_at' => $provider->observedAt,
            'fresh_until' => $provider->freshUntil,
            'created_at' => $provider->observedAt,
            'updated_at' => $provider->observedAt,
        ]);
    }

    public function saveConnection(ProviderConnection $connection): void
    {
        $this->assertProvider($connection->workspaceId, $connection->providerId);

        $this->database->connection()->table('provider_connections')->insert([
            'id' => $connection->id,
            'workspace_id' => $connection->workspaceId,
            'provider_id' => $connection->providerId,
            'name' => $connection->name,
            'readiness_status' => $connection->readiness->value,
            'auth_family' => $connection->authFamily->value,
            'secret_reference' => $connection->secretReference->value,
            'requested_scopes' => $this->encode($connection->requestedScopes),
            'granted_scopes' => $this->encode($connection->grantedScopes),
            'roles' => $this->encode($connection->roles),
            'access_tier' => $connection->accessTier,
            'region' => $connection->region,
            'principal_type' => $connection->principalType,
            'principal_reference' => $connection->principalReference,
            'provider_review_status' => $connection->providerReviewStatus,
            'token_expires_at' => $connection->tokenExpiresAt,
            'refresh_supported' => $connection->refreshSupported,
            'last_rotated_at' => $connection->lastRotatedAt,
            'metadata' => $this->encode($connection->metadata),
            'source_url' => $connection->sourceUrl,
            'source_version' => $connection->sourceVersion,
            'observed_at' => $connection->observedAt,
            'fresh_until' => $connection->freshUntil,
            'created_at' => $connection->observedAt,
            'updated_at' => $connection->observedAt,
        ]);
    }

    public function saveCapability(ProviderCapability $capability): void
    {
        $this->assertProvider($capability->workspaceId, $capability->providerId);
        $this->assertConnection($capability->workspaceId, $capability->providerId, $capability->connectionId);

        $this->database->connection()->table('provider_capabilities')->insert([
            'id' => $capability->id,
            'workspace_id' => $capability->workspaceId,
            'provider_id' => $capability->providerId,
            'connection_id' => $capability->connectionId,
            'operation' => $capability->operation,
            'support_status' => $capability->support->value,
            'required_scopes' => $this->encode($capability->requiredScopes),
            'required_roles' => $this->encode($capability->requiredRoles),
            'constraints' => $this->encode($capability->constraints),
            'source_url' => $capability->sourceUrl,
            'source_version' => $capability->sourceVersion,
            'observed_at' => $capability->observedAt,
            'fresh_until' => $capability->freshUntil,
            'created_at' => $capability->observedAt,
            'updated_at' => $capability->observedAt,
        ]);
    }

    public function saveQuota(ProviderQuota $quota): void
    {
        $this->assertProvider($quota->workspaceId, $quota->providerId);
        $this->assertConnection($quota->workspaceId, $quota->providerId, $quota->connectionId);

        $this->database->connection()->table('provider_quotas')->insert([
            'id' => $quota->id,
            'workspace_id' => $quota->workspaceId,
            'provider_id' => $quota->providerId,
            'connection_id' => $quota->connectionId,
            'operation' => $quota->operation,
            'scope_type' => $quota->scopeType,
            'scope_reference' => $quota->scopeReference,
            'unit' => $quota->unit,
            'window_type' => $quota->windowType,
            'window_seconds' => $quota->windowSeconds,
            'region' => $quota->region,
            'principal_type' => $quota->principalType,
            'principal_reference' => $quota->principalReference,
            'account_tier' => $quota->accountTier,
            'limit_value' => $quota->limitValue,
            'used_value' => $quota->usedValue,
            'remaining_value' => $quota->remainingValue,
            'resets_at' => $quota->resetsAt,
            'dynamically_discovered' => $quota->dynamicallyDiscovered,
            'discovery_key' => $quota->discoveryKey,
            'metadata' => $this->encode($quota->metadata),
            'source_url' => $quota->sourceUrl,
            'source_version' => $quota->sourceVersion,
            'observed_at' => $quota->observedAt,
            'fresh_until' => $quota->freshUntil,
            'created_at' => $quota->observedAt,
            'updated_at' => $quota->observedAt,
        ]);
    }

    public function findProvider(string $workspaceId, string $providerId): ?Provider
    {
        $row = $this->database->connection()->table('providers')
            ->where('workspace_id', $workspaceId)
            ->where('id', $providerId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateProvider($row) : null;
    }

    public function findConnection(string $workspaceId, string $connectionId): ?ProviderConnection
    {
        $row = $this->database->connection()->table('provider_connections')
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectionId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateConnection($row) : null;
    }

    public function findCapability(string $workspaceId, string $capabilityId): ?ProviderCapability
    {
        $row = $this->database->connection()->table('provider_capabilities')
            ->where('workspace_id', $workspaceId)
            ->where('id', $capabilityId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateCapability($row) : null;
    }

    public function findQuota(string $workspaceId, string $quotaId): ?ProviderQuota
    {
        $row = $this->database->connection()->table('provider_quotas')
            ->where('workspace_id', $workspaceId)
            ->where('id', $quotaId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateQuota($row) : null;
    }

    private function assertProvider(string $workspaceId, string $providerId): void
    {
        if ($this->findProvider($workspaceId, $providerId) === null) {
            throw new AuthorizationException('Provider access denied.');
        }
    }

    private function assertConnection(string $workspaceId, string $providerId, ?string $connectionId): void
    {
        if ($connectionId === null) {
            return;
        }

        $connection = $this->findConnection($workspaceId, $connectionId);
        if ($connection === null || $connection->providerId !== $providerId) {
            throw new AuthorizationException('Provider connection access denied.');
        }
    }

    private function hydrateProvider(stdClass $row): Provider
    {
        return new Provider(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            key: (string) $row->provider_key,
            displayName: (string) $row->display_name,
            category: $row->category === null ? null : (string) $row->category,
            metadata: $this->decode((string) $row->metadata),
            sourceUrl: (string) $row->source_url,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            freshUntil: $this->date($row->fresh_until),
        );
    }

    private function hydrateConnection(stdClass $row): ProviderConnection
    {
        return new ProviderConnection(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            providerId: (string) $row->provider_id,
            name: (string) $row->name,
            readiness: ProviderReadinessStatus::from((string) $row->readiness_status),
            authFamily: AuthFamily::from((string) $row->auth_family),
            secretReference: new SecretReference((string) $row->secret_reference),
            requestedScopes: $this->decode((string) $row->requested_scopes),
            grantedScopes: $this->decode((string) $row->granted_scopes),
            roles: $this->decode((string) $row->roles),
            accessTier: $row->access_tier === null ? null : (string) $row->access_tier,
            region: $row->region === null ? null : (string) $row->region,
            principalType: $row->principal_type === null ? null : (string) $row->principal_type,
            principalReference: $row->principal_reference === null ? null : (string) $row->principal_reference,
            providerReviewStatus: $row->provider_review_status === null ? null : (string) $row->provider_review_status,
            tokenExpiresAt: $this->date($row->token_expires_at),
            refreshSupported: (bool) $row->refresh_supported,
            lastRotatedAt: $this->date($row->last_rotated_at),
            metadata: $this->decode((string) $row->metadata),
            sourceUrl: (string) $row->source_url,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            freshUntil: $this->date($row->fresh_until),
        );
    }

    private function hydrateCapability(stdClass $row): ProviderCapability
    {
        return new ProviderCapability(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            providerId: (string) $row->provider_id,
            connectionId: $row->connection_id === null ? null : (string) $row->connection_id,
            operation: (string) $row->operation,
            support: CapabilitySupport::from((string) $row->support_status),
            requiredScopes: $this->decode((string) $row->required_scopes),
            requiredRoles: $this->decode((string) $row->required_roles),
            constraints: $this->decode((string) $row->constraints),
            sourceUrl: (string) $row->source_url,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            freshUntil: $this->date($row->fresh_until),
        );
    }

    private function hydrateQuota(stdClass $row): ProviderQuota
    {
        return new ProviderQuota(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            providerId: (string) $row->provider_id,
            connectionId: $row->connection_id === null ? null : (string) $row->connection_id,
            operation: (string) $row->operation,
            scopeType: (string) $row->scope_type,
            scopeReference: $row->scope_reference === null ? null : (string) $row->scope_reference,
            unit: (string) $row->unit,
            windowType: (string) $row->window_type,
            windowSeconds: $row->window_seconds === null ? null : (int) $row->window_seconds,
            region: $row->region === null ? null : (string) $row->region,
            principalType: $row->principal_type === null ? null : (string) $row->principal_type,
            principalReference: $row->principal_reference === null ? null : (string) $row->principal_reference,
            accountTier: $row->account_tier === null ? null : (string) $row->account_tier,
            limitValue: $row->limit_value === null ? null : (string) $row->limit_value,
            usedValue: $row->used_value === null ? null : (string) $row->used_value,
            remainingValue: $row->remaining_value === null ? null : (string) $row->remaining_value,
            resetsAt: $this->date($row->resets_at),
            dynamicallyDiscovered: (bool) $row->dynamically_discovered,
            discoveryKey: $row->discovery_key === null ? null : (string) $row->discovery_key,
            metadata: $this->decode((string) $row->metadata),
            sourceUrl: (string) $row->source_url,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            freshUntil: $this->date($row->fresh_until),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function decode(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
