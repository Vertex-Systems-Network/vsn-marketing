<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryFailoverEligibilityRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerKey;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerState;
use App\Modules\DeliveryEngine\Domain\DeliveryFailoverEligibility;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationSnapshot;
use App\Modules\DeliveryEngine\Domain\DeliveryRouteAcceptanceState;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseDeliveryFailoverEligibilityRepository implements DeliveryFailoverEligibilityRepository
{
    public function __construct(
        private DatabaseManager $database,
        private DeliveryCircuitBreakerPolicy $breakerPolicy,
    ) {}

    public function assess(
        DeliveryReconciliationSnapshot $snapshot,
        string $alternateProviderId,
        string $alternateProviderConnectionId,
        DateTimeImmutable $observedAt,
    ): DeliveryFailoverEligibility {
        $connection = $this->database->connection();
        $workspaceId = $snapshot->operation->workspaceId;
        $providerOperation = $snapshot->operation->channel->providerOperation();
        $sourceAttempt = $connection->table('delivery_attempts')
            ->where('workspace_id', $workspaceId)
            ->where('operation_id', $snapshot->operation->id)
            ->where('id', $snapshot->attemptId)
            ->lockForUpdate()
            ->first();
        $alternateConnection = $connection->table('provider_connections')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', $alternateProviderId)
            ->where('id', $alternateProviderConnectionId)
            ->lockForUpdate()
            ->first();

        $alternateExists = $alternateConnection instanceof stdClass;
        $sameWorkspace = $alternateExists;
        $providerExists = $alternateExists && $connection->table('providers')
            ->where('workspace_id', $workspaceId)
            ->where('id', $alternateProviderId)
            ->exists();
        $sourceRouteIntact = $sourceAttempt instanceof stdClass
            && $snapshot->operation->providerId === (string) $sourceAttempt->provider_id
            && $snapshot->operation->providerConnectionId === (string) $sourceAttempt->provider_connection_id;
        $tenantChecksPass = $sameWorkspace
            && $providerExists
            && $sourceRouteIntact;
        $connectionReady = $tenantChecksPass
            && (string) $alternateConnection->readiness_status === 'ready'
            && $this->isFresh($alternateConnection->fresh_until, $observedAt);

        $capability = $tenantChecksPass
            ? $connection->table('provider_capabilities')
                ->where('workspace_id', $workspaceId)
                ->where('provider_id', $alternateProviderId)
                ->where('connection_id', $alternateProviderConnectionId)
                ->where('operation', $providerOperation)
                ->lockForUpdate()
                ->first()
            : null;
        $capabilityCompatible = $capability instanceof stdClass
            && (string) $capability->support_status === 'supported'
            && $this->isFresh($capability->fresh_until, $observedAt);
        $policyAllows = $connectionReady
            && $capabilityCompatible
            && $this->authorizationPolicyAllows($alternateConnection, $capability);
        $quotaAvailable = $policyAllows && $this->quotaAvailable(
            workspaceId: $workspaceId,
            providerId: $alternateProviderId,
            connectionId: $alternateProviderConnectionId,
            providerOperation: $providerOperation,
            observedAt: $observedAt,
        );
        $breakerAllows = $policyAllows && $this->breakerAllows(
            workspaceId: $workspaceId,
            providerId: $alternateProviderId,
            connectionId: $alternateProviderConnectionId,
            providerOperation: $providerOperation,
            observedAt: $observedAt,
        );

        return new DeliveryFailoverEligibility(
            previousRouteAcceptance: $this->previousRouteAcceptance($snapshot),
            sameWorkspace: $sameWorkspace,
            tenantChecksPass: $tenantChecksPass,
            capabilityCompatible: $capabilityCompatible,
            policyAllows: $policyAllows,
            connectionReady: $connectionReady,
            quotaAvailable: $quotaAvailable,
            breakerAllows: $breakerAllows,
        );
    }

    private function previousRouteAcceptance(
        DeliveryReconciliationSnapshot $snapshot,
    ): DeliveryRouteAcceptanceState {
        if (
            $snapshot->resolution === DeliveryReconciliationResolution::Accepted
            && $snapshot->providerAccepted
        ) {
            return DeliveryRouteAcceptanceState::Accepted;
        }

        if (
            $snapshot->resolution === DeliveryReconciliationResolution::NotAcceptedRetrySafe
            && ! $snapshot->providerAccepted
            && $snapshot->acceptanceKnownNotOccurred
            && $snapshot->retrySafe
            && ! $snapshot->operatorActionRequired
        ) {
            return DeliveryRouteAcceptanceState::KnownNotAccepted;
        }

        return DeliveryRouteAcceptanceState::Ambiguous;
    }

    private function authorizationPolicyAllows(stdClass $connection, stdClass $capability): bool
    {
        $grantedScopes = $this->jsonStringList($connection->granted_scopes);
        $roles = $this->jsonStringList($connection->roles);
        $requiredScopes = $this->jsonStringList($capability->required_scopes);
        $requiredRoles = $this->jsonStringList($capability->required_roles);

        if (
            $grantedScopes === null
            || $roles === null
            || $requiredScopes === null
            || $requiredRoles === null
        ) {
            return false;
        }

        return array_diff($requiredScopes, $grantedScopes) === []
            && array_diff($requiredRoles, $roles) === [];
    }

    private function quotaAvailable(
        string $workspaceId,
        string $providerId,
        string $connectionId,
        string $providerOperation,
        DateTimeImmutable $observedAt,
    ): bool {
        $connection = $this->database->connection();
        $quotas = $connection->table('provider_quotas')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', $providerId)
            ->where('connection_id', $connectionId)
            ->where('operation', $providerOperation)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($quotas->isEmpty()) {
            return false;
        }

        foreach ($quotas as $quota) {
            if (! $quota instanceof stdClass || ! $this->isFresh($quota->fresh_until, $observedAt)) {
                return false;
            }

            if (
                $quota->resets_at !== null
                && new DateTimeImmutable((string) $quota->resets_at) <= $observedAt
            ) {
                return false;
            }

            $available = $this->availableUnits($quota);
            $required = $this->requiredUnits($quota);
            if ($available === null || $required === null) {
                return false;
            }

            $consumed = (float) $connection->table('delivery_operation_quota_consumptions')
                ->where('workspace_id', $workspaceId)
                ->where('quota_id', (string) $quota->id)
                ->sum('units');
            if (($available - $consumed) < $required) {
                return false;
            }
        }

        return true;
    }

    private function breakerAllows(
        string $workspaceId,
        string $providerId,
        string $connectionId,
        string $providerOperation,
        DateTimeImmutable $observedAt,
    ): bool {
        $key = new DeliveryCircuitBreakerKey(
            workspaceId: $workspaceId,
            providerConnectionId: $connectionId,
            operationClass: $providerOperation,
        );
        $breaker = $this->database->connection()->table('delivery_circuit_breakers')
            ->where('id', $key->fingerprint())
            ->where('workspace_id', $workspaceId)
            ->where('provider_connection_id', $connectionId)
            ->where('operation_class', $providerOperation)
            ->lockForUpdate()
            ->first();

        if (! $breaker instanceof stdClass) {
            return true;
        }

        if ((string) $breaker->provider_id !== $providerId) {
            return false;
        }

        $decision = $this->breakerPolicy->beforeAttempt(
            state: DeliveryCircuitBreakerState::from((string) $breaker->state),
            now: $observedAt,
            nextProbeAt: $breaker->next_probe_at === null
                ? null
                : new DateTimeImmutable((string) $breaker->next_probe_at),
            probeInFlight: (bool) $breaker->probe_in_flight,
        );

        return ! $decision->workHeld;
    }

    private function isFresh(mixed $freshUntil, DateTimeImmutable $observedAt): bool
    {
        return $freshUntil === null || new DateTimeImmutable((string) $freshUntil) >= $observedAt;
    }

    /** @return list<string>|null */
    private function jsonStringList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
        }

        return $value;
    }

    private function availableUnits(stdClass $quota): ?float
    {
        if ($quota->remaining_value !== null) {
            return max(0.0, (float) $quota->remaining_value);
        }

        if ($quota->limit_value !== null && $quota->used_value !== null) {
            return max(0.0, (float) $quota->limit_value - (float) $quota->used_value);
        }

        return null;
    }

    private function requiredUnits(stdClass $quota): ?float
    {
        $unit = (string) $quota->unit;
        if (in_array($unit, ['request', 'recipient'], true)) {
            return 1.0;
        }

        $metadata = json_decode((string) $quota->metadata, true);
        if (! is_array($metadata)) {
            return null;
        }

        $cost = $metadata['operation_cost_units'] ?? null;
        if (! is_int($cost) && ! is_float($cost) && ! (is_string($cost) && is_numeric($cost))) {
            return null;
        }

        $required = (float) $cost;

        return is_finite($required) && $required > 0 ? $required : null;
    }
}
