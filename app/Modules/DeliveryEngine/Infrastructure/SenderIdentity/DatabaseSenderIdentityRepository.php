<?php

namespace App\Modules\DeliveryEngine\Infrastructure\SenderIdentity;

use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDimension;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidence;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidenceStatus;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomain;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomainLifecycle;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomainName;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderEligibilityContext;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderIdentity;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderIdentityLifecycle;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderPurpose;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderRecordGuard;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class DatabaseSenderIdentityRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function createDomain(SenderDomain $domain): SenderDomain
    {
        return $this->database->connection()->transaction(function () use ($domain): SenderDomain {
            $existing = $this->database->connection()->table('sender_domains')
                ->where('workspace_id', $domain->workspaceId)
                ->where('idempotency_key', $domain->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateDomain($existing);
                $this->assertDomainCreateReplay($stored, $domain);

                return $stored;
            }

            $this->assertDomainIdentityAvailable($domain);

            try {
                $this->database->connection()->table('sender_domains')->insert([
                    'id' => $domain->id,
                    'workspace_id' => $domain->workspaceId,
                    'canonical_domain' => $domain->domain->value,
                    'lifecycle_state' => $domain->lifecycle->value,
                    'idempotency_key' => $domain->idempotencyKey,
                    'metadata' => $this->encode($domain->metadata),
                    'created_at' => $domain->createdAt,
                    'updated_at' => $domain->updatedAt,
                ]);
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('sender_domains')
                    ->where('workspace_id', $domain->workspaceId)
                    ->where('idempotency_key', $domain->idempotencyKey)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $stored = $this->hydrateDomain($raceWinner);
                    $this->assertDomainCreateReplay($stored, $domain);

                    return $stored;
                }

                throw $exception;
            }

            return $domain;
        });
    }

    public function updateDomain(SenderDomain $domain): SenderDomain
    {
        return $this->database->connection()->transaction(function () use ($domain): SenderDomain {
            $row = $this->database->connection()->table('sender_domains')
                ->where('workspace_id', $domain->workspaceId)
                ->where('id', $domain->id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                $this->denyIfForeignDomainIdExists($domain->workspaceId, $domain->id);
                throw new InvalidArgumentException('Sender domain does not exist in this workspace.');
            }

            $stored = $this->hydrateDomain($row);
            $this->assertDomainUpdateIdentity($stored, $domain);

            if ($stored->updatedAt > $domain->updatedAt) {
                throw new InvalidArgumentException('Sender domain update time must not move backwards.');
            }

            if ($stored->updatedAt == $domain->updatedAt) {
                if ($stored->lifecycle !== $domain->lifecycle || $stored->metadata != $domain->metadata) {
                    throw new InvalidArgumentException('Sender domain changes must advance updatedAt.');
                }

                return $stored;
            }

            $this->database->connection()->table('sender_domains')
                ->where('workspace_id', $domain->workspaceId)
                ->where('id', $domain->id)
                ->update([
                    'lifecycle_state' => $domain->lifecycle->value,
                    'metadata' => $this->encode($domain->metadata),
                    'updated_at' => $domain->updatedAt,
                ]);

            return $domain;
        });
    }

    public function findDomain(string $workspaceId, string $domainId): ?SenderDomain
    {
        $row = $this->database->connection()->table('sender_domains')
            ->where('workspace_id', $workspaceId)
            ->where('id', $domainId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateDomain($row) : null;
    }

    public function findDomainByName(string $workspaceId, SenderDomainName $domain): ?SenderDomain
    {
        $row = $this->database->connection()->table('sender_domains')
            ->where('workspace_id', $workspaceId)
            ->where('canonical_domain', $domain->value)
            ->first();

        return $row instanceof stdClass ? $this->hydrateDomain($row) : null;
    }

    public function createIdentity(SenderIdentity $identity): SenderIdentity
    {
        return $this->database->connection()->transaction(function () use ($identity): SenderIdentity {
            $this->assertIdentityRelationships($identity);

            $existing = $this->database->connection()->table('sender_identities')
                ->where('workspace_id', $identity->workspaceId)
                ->where('idempotency_key', $identity->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateIdentity($existing);
                $this->assertIdentityCreateReplay($stored, $identity);

                return $stored;
            }

            $this->assertSenderIdentityAvailable($identity);

            try {
                $this->database->connection()->table('sender_identities')->insert($this->identityPayload($identity));
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('sender_identities')
                    ->where('workspace_id', $identity->workspaceId)
                    ->where('idempotency_key', $identity->idempotencyKey)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $stored = $this->hydrateIdentity($raceWinner);
                    $this->assertIdentityCreateReplay($stored, $identity);

                    return $stored;
                }

                throw $exception;
            }

            return $identity;
        });
    }

    public function updateIdentity(SenderIdentity $identity): SenderIdentity
    {
        return $this->database->connection()->transaction(function () use ($identity): SenderIdentity {
            $this->assertIdentityRelationships($identity);

            $row = $this->database->connection()->table('sender_identities')
                ->where('workspace_id', $identity->workspaceId)
                ->where('id', $identity->id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                $this->denyIfForeignIdentityIdExists($identity->workspaceId, $identity->id);
                throw new InvalidArgumentException('Sender identity does not exist in this workspace.');
            }

            $stored = $this->hydrateIdentity($row);
            $this->assertIdentityUpdateIdentity($stored, $identity);

            if ($stored->updatedAt > $identity->updatedAt) {
                throw new InvalidArgumentException('Sender identity update time must not move backwards.');
            }

            if ($stored->updatedAt == $identity->updatedAt) {
                if (! $this->sameMutableIdentityState($stored, $identity)) {
                    throw new InvalidArgumentException('Sender identity changes must advance updatedAt.');
                }

                return $stored;
            }

            $payload = $this->identityPayload($identity);
            unset(
                $payload['id'],
                $payload['workspace_id'],
                $payload['sender_domain_id'],
                $payload['provider_connection_id'],
                $payload['local_part'],
                $payload['email_address'],
                $payload['idempotency_key'],
                $payload['created_at'],
            );

            $this->database->connection()->table('sender_identities')
                ->where('workspace_id', $identity->workspaceId)
                ->where('id', $identity->id)
                ->update($payload);

            return $identity;
        });
    }

    public function findIdentity(string $workspaceId, string $identityId): ?SenderIdentity
    {
        $row = $this->database->connection()->table('sender_identities')
            ->where('workspace_id', $workspaceId)
            ->where('id', $identityId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateIdentity($row) : null;
    }

    public function findIdentityByEmail(string $workspaceId, string $emailAddress): ?SenderIdentity
    {
        $row = $this->database->connection()->table('sender_identities')
            ->where('workspace_id', $workspaceId)
            ->where('email_address', strtolower(trim($emailAddress)))
            ->first();

        return $row instanceof stdClass ? $this->hydrateIdentity($row) : null;
    }

    public function appendAuthenticationEvidence(AuthenticationEvidence $evidence): AuthenticationEvidence
    {
        SenderRecordGuard::assertNoSecretMaterial($evidence->publicMaterial, 'public_material');
        SenderRecordGuard::assertNoSecretMaterial($evidence->redactedEvidence, 'redacted_evidence');
        $this->assertSenderDomainExists($evidence->workspaceId, $evidence->senderDomainId);

        return $this->database->connection()->transaction(function () use ($evidence): AuthenticationEvidence {
            $existing = $this->database->connection()->table('sender_authentication_evidence')
                ->where('workspace_id', $evidence->workspaceId)
                ->where('sender_domain_id', $evidence->senderDomainId)
                ->where('dimension', $evidence->dimension->value)
                ->where('evidence_version', $evidence->evidenceVersion)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateEvidence($existing);
                $this->assertEvidenceReplay($stored, $evidence);

                return $stored;
            }

            $this->denyIfForeignEvidenceIdExists($evidence->workspaceId, $evidence->id);

            try {
                $this->database->connection()->table('sender_authentication_evidence')->insert([
                    'id' => $evidence->id,
                    'workspace_id' => $evidence->workspaceId,
                    'sender_domain_id' => $evidence->senderDomainId,
                    'dimension' => $evidence->dimension->value,
                    'evidence_status' => $evidence->status->value,
                    'evidence_version' => $evidence->evidenceVersion,
                    'source_type' => $evidence->sourceType,
                    'provider_key' => $evidence->providerKey,
                    'public_material' => $this->encode($evidence->publicMaterial),
                    'redacted_evidence' => $this->encode($evidence->redactedEvidence),
                    'source_url' => $evidence->sourceUrl,
                    'source_version' => $evidence->sourceVersion,
                    'observed_at' => $evidence->observedAt,
                    'fresh_until' => $evidence->freshUntil,
                    'recorded_at' => $evidence->recordedAt,
                    'metadata' => $this->encode([]),
                    'created_at' => $evidence->recordedAt,
                    'updated_at' => $evidence->recordedAt,
                ]);
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('sender_authentication_evidence')
                    ->where('workspace_id', $evidence->workspaceId)
                    ->where('sender_domain_id', $evidence->senderDomainId)
                    ->where('dimension', $evidence->dimension->value)
                    ->where('evidence_version', $evidence->evidenceVersion)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $stored = $this->hydrateEvidence($raceWinner);
                    $this->assertEvidenceReplay($stored, $evidence);

                    return $stored;
                }

                throw $exception;
            }

            return $evidence;
        });
    }

    /** @return list<AuthenticationEvidence> */
    public function authenticationEvidence(string $workspaceId, string $senderDomainId): array
    {
        if (! $this->senderDomainExists($workspaceId, $senderDomainId)) {
            return [];
        }

        return $this->database->connection()->table('sender_authentication_evidence')
            ->where('workspace_id', $workspaceId)
            ->where('sender_domain_id', $senderDomainId)
            ->orderBy('observed_at')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): AuthenticationEvidence => $this->hydrateEvidence($row))
            ->values()
            ->all();
    }

    /** @return list<AuthenticationEvidence> */
    public function authenticationEvidenceForDimension(
        string $workspaceId,
        string $senderDomainId,
        AuthenticationDimension $dimension,
    ): array {
        if (! $this->senderDomainExists($workspaceId, $senderDomainId)) {
            return [];
        }

        return $this->database->connection()->table('sender_authentication_evidence')
            ->where('workspace_id', $workspaceId)
            ->where('sender_domain_id', $senderDomainId)
            ->where('dimension', $dimension->value)
            ->orderBy('observed_at')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): AuthenticationEvidence => $this->hydrateEvidence($row))
            ->values()
            ->all();
    }

    private function assertDomainIdentityAvailable(SenderDomain $domain): void
    {
        $byId = $this->database->connection()->table('sender_domains')
            ->where('id', $domain->id)
            ->first();

        if ($byId instanceof stdClass) {
            if ((string) $byId->workspace_id !== $domain->workspaceId) {
                throw new AuthorizationException('Sender domain access denied.');
            }

            throw new InvalidArgumentException('Sender domain ID already exists in this workspace.');
        }

        $byName = $this->database->connection()->table('sender_domains')
            ->where('workspace_id', $domain->workspaceId)
            ->where('canonical_domain', $domain->domain->value)
            ->first();

        if ($byName instanceof stdClass) {
            throw new InvalidArgumentException('Sender domain already exists in this workspace with a different idempotency key.');
        }
    }

    private function assertDomainCreateReplay(SenderDomain $stored, SenderDomain $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->domain->value !== $candidate->domain->value
            || $stored->idempotencyKey !== $candidate->idempotencyKey
        ) {
            throw new InvalidArgumentException('Sender domain idempotency key conflicts with another canonical domain.');
        }
    }

    private function assertDomainUpdateIdentity(SenderDomain $stored, SenderDomain $candidate): void
    {
        if (
            $stored->workspaceId !== $candidate->workspaceId
            || $stored->domain->value !== $candidate->domain->value
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || $stored->createdAt != $candidate->createdAt
        ) {
            throw new InvalidArgumentException('Sender domain canonical identity is immutable.');
        }
    }

    private function assertSenderIdentityAvailable(SenderIdentity $identity): void
    {
        $byId = $this->database->connection()->table('sender_identities')
            ->where('id', $identity->id)
            ->first();

        if ($byId instanceof stdClass) {
            if ((string) $byId->workspace_id !== $identity->workspaceId) {
                throw new AuthorizationException('Sender identity access denied.');
            }

            throw new InvalidArgumentException('Sender identity ID already exists in this workspace.');
        }

        $byEmail = $this->database->connection()->table('sender_identities')
            ->where('workspace_id', $identity->workspaceId)
            ->where('email_address', strtolower($identity->emailAddress()))
            ->first();

        if ($byEmail instanceof stdClass) {
            throw new InvalidArgumentException('Sender email address already exists in this workspace with a different idempotency key.');
        }
    }

    private function assertIdentityCreateReplay(SenderIdentity $stored, SenderIdentity $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->senderDomainId !== $candidate->senderDomainId
            || $stored->identityKey() !== $candidate->identityKey()
            || $stored->providerConnectionId !== $candidate->providerConnectionId
            || $stored->idempotencyKey !== $candidate->idempotencyKey
        ) {
            throw new InvalidArgumentException('Sender identity idempotency key conflicts with another canonical identity.');
        }
    }

    private function assertIdentityUpdateIdentity(SenderIdentity $stored, SenderIdentity $candidate): void
    {
        if (
            $stored->workspaceId !== $candidate->workspaceId
            || $stored->senderDomainId !== $candidate->senderDomainId
            || $stored->domain->value !== $candidate->domain->value
            || $stored->localPart !== $candidate->localPart
            || $stored->identityKey() !== $candidate->identityKey()
            || $stored->providerConnectionId !== $candidate->providerConnectionId
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || $stored->createdAt != $candidate->createdAt
        ) {
            throw new InvalidArgumentException('Sender identity canonical identity is immutable.');
        }
    }

    private function sameMutableIdentityState(SenderIdentity $left, SenderIdentity $right): bool
    {
        return $left->displayName === $right->displayName
            && $left->replyToAddress === $right->replyToAddress
            && $left->lifecycle === $right->lifecycle
            && $left->eligibility == $right->eligibility
            && $left->metadata == $right->metadata;
    }

    private function assertIdentityRelationships(SenderIdentity $identity): void
    {
        $domain = $this->database->connection()->table('sender_domains')
            ->where('workspace_id', $identity->workspaceId)
            ->where('id', $identity->senderDomainId)
            ->first();

        if (! $domain instanceof stdClass) {
            throw new AuthorizationException('Sender domain access denied.');
        }

        if ((string) $domain->canonical_domain !== $identity->domain->value) {
            throw new InvalidArgumentException('Sender identity domain does not match its canonical sender domain.');
        }

        if ($identity->providerConnectionId === null) {
            return;
        }

        $connection = $this->database->connection()->table('provider_connections as pc')
            ->join('providers as p', function ($join): void {
                $join->on('p.id', '=', 'pc.provider_id')
                    ->on('p.workspace_id', '=', 'pc.workspace_id');
            })
            ->where('pc.workspace_id', $identity->workspaceId)
            ->where('pc.id', $identity->providerConnectionId)
            ->select('p.provider_key')
            ->first();

        if (! $connection instanceof stdClass) {
            throw new AuthorizationException('Provider connection access denied.');
        }

        if ((string) $connection->provider_key !== $identity->eligibility->providerKey) {
            throw new InvalidArgumentException('Sender identity provider context does not match its provider connection.');
        }
    }

    private function identityPayload(SenderIdentity $identity): array
    {
        return [
            'id' => $identity->id,
            'workspace_id' => $identity->workspaceId,
            'sender_domain_id' => $identity->senderDomainId,
            'provider_connection_id' => $identity->providerConnectionId,
            'local_part' => $identity->localPart,
            'email_address' => strtolower($identity->emailAddress()),
            'display_name' => $identity->displayName,
            'reply_to_address' => $identity->replyToAddress,
            'lifecycle_state' => $identity->lifecycle->value,
            'purpose' => $identity->eligibility->purpose->value,
            'provider_key' => $identity->eligibility->providerKey,
            'policy_context' => $this->encode([
                'policy_key' => $identity->eligibility->policyKey,
                'policy_version' => $identity->eligibility->policyVersion,
                'context' => $identity->eligibility->policyContext,
            ]),
            'idempotency_key' => $identity->idempotencyKey,
            'metadata' => $this->encode($identity->metadata),
            'created_at' => $identity->createdAt,
            'updated_at' => $identity->updatedAt,
        ];
    }

    private function hydrateDomain(stdClass $row): SenderDomain
    {
        return new SenderDomain(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            domain: new SenderDomainName((string) $row->canonical_domain),
            lifecycle: SenderDomainLifecycle::from((string) $row->lifecycle_state),
            idempotencyKey: (string) $row->idempotency_key,
            metadata: $this->decode($row->metadata, 'sender_domains.metadata'),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function hydrateIdentity(stdClass $row): SenderIdentity
    {
        $domain = $this->database->connection()->table('sender_domains')
            ->where('workspace_id', (string) $row->workspace_id)
            ->where('id', (string) $row->sender_domain_id)
            ->first();

        if (! $domain instanceof stdClass) {
            throw new UnexpectedValueException('Stored sender identity references a missing sender domain.');
        }

        $policy = $this->decode($row->policy_context, 'sender_identities.policy_context');
        $context = $policy['context'] ?? $policy['policy_context'] ?? [];
        if (! is_array($context)) {
            throw new UnexpectedValueException('Stored sender identity policy context must contain an array context.');
        }

        $policyKey = $this->nullableString($policy['policy_key'] ?? null, 'sender_identities.policy_context.policy_key');
        $policyVersion = $this->nullableString($policy['policy_version'] ?? null, 'sender_identities.policy_context.policy_version');

        return new SenderIdentity(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            senderDomainId: (string) $row->sender_domain_id,
            domain: new SenderDomainName((string) $domain->canonical_domain),
            localPart: (string) $row->local_part,
            displayName: $row->display_name === null ? null : (string) $row->display_name,
            replyToAddress: $row->reply_to_address === null ? null : (string) $row->reply_to_address,
            lifecycle: SenderIdentityLifecycle::from((string) $row->lifecycle_state),
            eligibility: new SenderEligibilityContext(
                purpose: SenderPurpose::from((string) $row->purpose),
                providerKey: $row->provider_key === null ? null : (string) $row->provider_key,
                policyKey: $policyKey,
                policyVersion: $policyVersion,
                policyContext: $context,
            ),
            providerConnectionId: $row->provider_connection_id === null ? null : (string) $row->provider_connection_id,
            idempotencyKey: (string) $row->idempotency_key,
            metadata: $this->decode($row->metadata, 'sender_identities.metadata'),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function hydrateEvidence(stdClass $row): AuthenticationEvidence
    {
        return new AuthenticationEvidence(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            senderDomainId: (string) $row->sender_domain_id,
            dimension: AuthenticationDimension::from((string) $row->dimension),
            status: AuthenticationEvidenceStatus::from((string) $row->evidence_status),
            evidenceVersion: (string) $row->evidence_version,
            sourceType: (string) $row->source_type,
            providerKey: $row->provider_key === null ? null : (string) $row->provider_key,
            publicMaterial: $this->decode($row->public_material, 'sender_authentication_evidence.public_material'),
            redactedEvidence: $this->decode($row->redacted_evidence, 'sender_authentication_evidence.redacted_evidence'),
            sourceUrl: $row->source_url === null ? null : (string) $row->source_url,
            sourceVersion: $row->source_version === null ? null : (string) $row->source_version,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            freshUntil: $row->fresh_until === null ? null : new DateTimeImmutable((string) $row->fresh_until),
            recordedAt: new DateTimeImmutable((string) $row->recorded_at),
        );
    }

    private function assertEvidenceReplay(AuthenticationEvidence $stored, AuthenticationEvidence $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->senderDomainId !== $candidate->senderDomainId
            || $stored->dimension !== $candidate->dimension
            || $stored->status !== $candidate->status
            || $stored->evidenceVersion !== $candidate->evidenceVersion
            || $stored->sourceType !== $candidate->sourceType
            || $stored->providerKey !== $candidate->providerKey
            || $stored->publicMaterial != $candidate->publicMaterial
            || $stored->redactedEvidence != $candidate->redactedEvidence
            || $stored->sourceUrl !== $candidate->sourceUrl
            || $stored->sourceVersion !== $candidate->sourceVersion
            || $stored->observedAt != $candidate->observedAt
            || $stored->freshUntil != $candidate->freshUntil
            || $stored->recordedAt != $candidate->recordedAt
        ) {
            throw new InvalidArgumentException('Authentication evidence version conflicts with different immutable evidence.');
        }
    }

    private function assertSenderDomainExists(string $workspaceId, string $senderDomainId): void
    {
        if (! $this->senderDomainExists($workspaceId, $senderDomainId)) {
            throw new AuthorizationException('Sender domain access denied.');
        }
    }

    private function senderDomainExists(string $workspaceId, string $senderDomainId): bool
    {
        return $this->database->connection()->table('sender_domains')
            ->where('workspace_id', $workspaceId)
            ->where('id', $senderDomainId)
            ->exists();
    }

    private function denyIfForeignDomainIdExists(string $workspaceId, string $domainId): void
    {
        if ($this->database->connection()->table('sender_domains')
            ->where('id', $domainId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Sender domain access denied.');
        }
    }

    private function denyIfForeignIdentityIdExists(string $workspaceId, string $identityId): void
    {
        if ($this->database->connection()->table('sender_identities')
            ->where('id', $identityId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Sender identity access denied.');
        }
    }

    private function denyIfForeignEvidenceIdExists(string $workspaceId, string $evidenceId): void
    {
        if ($this->database->connection()->table('sender_authentication_evidence')
            ->where('id', $evidenceId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Authentication evidence access denied.');
        }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Stored '.$field.' must be JSON.');
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Stored '.$field.' contains invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Stored '.$field.' must decode to an array.');
        }

        return $decoded;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Stored '.$field.' must be a string or null.');
        }

        return $value;
    }
}
