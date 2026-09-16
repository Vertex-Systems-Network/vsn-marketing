<?php

namespace App\Modules\DeliveryEngine\Infrastructure\SenderIdentity;

use App\Modules\DeliveryEngine\Application\SenderSync\SenderSynchronizationOutcome;
use App\Modules\DeliveryEngine\Application\SenderSync\SenderSynchronizationRequest;
use App\Modules\DeliveryEngine\Application\SenderSync\SenderSynchronizationResult;
use App\Modules\DeliveryEngine\Application\SenderVerification\SenderVerificationObservationOutcome;
use App\Modules\DeliveryEngine\Application\SenderVerification\SenderVerificationRequest;
use App\Modules\DeliveryEngine\Application\SenderVerification\SenderVerificationResult;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidence;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderRecordGuard;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicy;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseSenderOperationRecorder
{
    public function __construct(private DatabaseManager $database) {}

    public function recordVerification(
        SenderVerificationRequest $request,
        SenderVerificationResult $result,
    ): void {
        $this->assertVerificationScope($request, $result);

        $auditEvidence = [
            'observation_outcome' => $result->observationOutcome->value,
            'authentication' => [
                'readiness' => $result->authentication->readiness->value,
                'reasons' => $result->authentication->reasons,
                'evidence_versions' => $result->authentication->evidenceVersions,
            ],
            'provider_policy' => [
                'classification' => $result->providerPolicy->classification->value,
                'policy_key' => $result->providerPolicy->policyKey,
                'policy_version' => $result->providerPolicy->policyVersion,
                'reasons' => $result->providerPolicy->reasons,
                'requirements' => $result->providerPolicy->requirements,
                'provenance_url' => $result->providerPolicy->provenanceUrl,
            ],
            'eligible_for_later_sending_evaluation' => $result->eligibleForLaterSendingEvaluation,
            'production_activation_allowed' => $result->productionActivationAllowed,
            'reasons' => $result->reasons,
        ];

        $this->record(
            workspaceId: $request->workspaceId,
            senderDomainId: $request->senderDomainId,
            providerKey: $request->providerKey,
            operationType: 'verification',
            operationState: $request->observationOutcome === SenderVerificationObservationOutcome::Timeout
                ? 'timed_out'
                : 'completed',
            outcomeClass: $request->observationOutcome->value,
            idempotencyKey: $request->operationKey,
            requestFingerprint: $this->verificationFingerprint($request),
            providerOperationReference: null,
            ambiguousOutcome: $request->observationOutcome === SenderVerificationObservationOutcome::Ambiguous,
            startedAt: $request->evaluatedAt,
            timeoutAt: $request->observationOutcome === SenderVerificationObservationOutcome::Timeout
                ? $request->evaluatedAt
                : null,
            completedAt: $request->observationOutcome === SenderVerificationObservationOutcome::Timeout
                ? null
                : $request->evaluatedAt,
            auditEvidence: $auditEvidence,
            metadata: [
                'operation_key' => $request->operationKey,
                'record_type' => 'sender_verification',
            ],
        );
    }

    public function recordSynchronization(
        SenderSynchronizationRequest $request,
        SenderSynchronizationResult $result,
    ): void {
        $this->assertSynchronizationScope($request, $result);

        $auditEvidence = [
            'provider_outcome' => $result->providerOutcome->value,
            'reconciliation_required' => $result->reconciliationRequired,
            'eligible_for_later_sending_evaluation' => $result->eligibleForLaterSendingEvaluation,
            'production_activation_allowed' => $result->productionActivationAllowed,
            'reasons' => $result->reasons,
            'public_evidence' => $result->publicEvidence,
            'verification_operation_key' => $request->verification->operationKey,
        ];

        $this->record(
            workspaceId: $request->workspaceId,
            senderDomainId: $request->senderDomainId,
            providerKey: $request->providerKey,
            operationType: 'synchronization',
            operationState: $request->providerOutcome === SenderSynchronizationOutcome::Timeout
                ? 'timed_out'
                : 'completed',
            outcomeClass: $request->providerOutcome->value,
            idempotencyKey: $request->operationKey,
            requestFingerprint: $this->synchronizationFingerprint($request),
            providerOperationReference: $request->providerReference,
            ambiguousOutcome: $request->providerOutcome === SenderSynchronizationOutcome::Ambiguous,
            startedAt: $request->observedAt,
            timeoutAt: $request->providerOutcome === SenderSynchronizationOutcome::Timeout
                ? $request->observedAt
                : null,
            completedAt: $request->providerOutcome === SenderSynchronizationOutcome::Timeout
                ? null
                : $request->observedAt,
            auditEvidence: $auditEvidence,
            metadata: [
                'operation_key' => $request->operationKey,
                'record_type' => 'sender_synchronization',
                'source_version' => $request->sourceVersion,
            ],
        );
    }

    private function assertVerificationScope(
        SenderVerificationRequest $request,
        SenderVerificationResult $result,
    ): void {
        if (
            $result->operationKey !== $request->operationKey
            || $result->workspaceId !== $request->workspaceId
            || $result->senderDomainId !== $request->senderDomainId
            || $result->providerKey !== $request->providerKey
            || $result->evaluatedAt != $request->evaluatedAt
            || $result->observationOutcome !== $request->observationOutcome
        ) {
            throw new InvalidArgumentException('Sender verification audit result does not match the recorded request scope.');
        }

        if ($result->productionActivationAllowed) {
            throw new InvalidArgumentException('Sender verification audit cannot record implicit production activation.');
        }
    }

    private function assertSynchronizationScope(
        SenderSynchronizationRequest $request,
        SenderSynchronizationResult $result,
    ): void {
        if (
            $result->operationKey !== $request->operationKey
            || $result->workspaceId !== $request->workspaceId
            || $result->senderDomainId !== $request->senderDomainId
            || $result->providerKey !== $request->providerKey
            || $result->observedAt != $request->observedAt
            || $result->providerOutcome !== $request->providerOutcome
        ) {
            throw new InvalidArgumentException('Sender synchronization audit result does not match the recorded request scope.');
        }

        if ($result->productionActivationAllowed) {
            throw new InvalidArgumentException('Sender synchronization audit cannot record implicit production activation.');
        }
    }

    private function record(
        string $workspaceId,
        string $senderDomainId,
        string $providerKey,
        string $operationType,
        string $operationState,
        string $outcomeClass,
        string $idempotencyKey,
        string $requestFingerprint,
        ?string $providerOperationReference,
        bool $ambiguousOutcome,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $timeoutAt,
        ?DateTimeImmutable $completedAt,
        array $auditEvidence,
        array $metadata,
    ): void {
        SenderRecordGuard::assertNoSecretMaterial($auditEvidence, 'sender_operation.audit_evidence');
        SenderRecordGuard::assertNoSecretMaterial($metadata, 'sender_operation.metadata');

        if (mb_strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException('Sender operation idempotency key must not exceed 191 characters.');
        }

        if ($providerOperationReference !== null && mb_strlen($providerOperationReference) > 191) {
            throw new InvalidArgumentException('Sender provider operation reference must not exceed 191 characters.');
        }

        $canonicalAuditEvidence = $this->canonicalJson($auditEvidence);
        $canonicalMetadata = $this->canonicalJson($metadata);

        $this->database->connection()->transaction(function () use (
            $workspaceId,
            $senderDomainId,
            $providerKey,
            $operationType,
            $operationState,
            $outcomeClass,
            $idempotencyKey,
            $requestFingerprint,
            $providerOperationReference,
            $ambiguousOutcome,
            $startedAt,
            $timeoutAt,
            $completedAt,
            $canonicalAuditEvidence,
            $canonicalMetadata,
        ): void {
            $this->assertDomainOwnedByWorkspace($workspaceId, $senderDomainId);

            $existing = $this->database->connection()->table('sender_verification_operations')
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $this->assertReplayMatches(
                    existing: $existing,
                    operationType: $operationType,
                    operationState: $operationState,
                    outcomeClass: $outcomeClass,
                    requestFingerprint: $requestFingerprint,
                    providerOperationReference: $providerOperationReference,
                    ambiguousOutcome: $ambiguousOutcome,
                    canonicalAuditEvidence: $canonicalAuditEvidence,
                    canonicalMetadata: $canonicalMetadata,
                );

                return;
            }

            $values = [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'sender_domain_id' => $senderDomainId,
                'sender_identity_id' => null,
                'provider_key' => $providerKey,
                'operation_type' => $operationType,
                'operation_state' => $operationState,
                'outcome_class' => $outcomeClass,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $requestFingerprint,
                'provider_operation_reference' => $providerOperationReference,
                'mutation_mode' => 'read_only',
                'production_activation_permitted' => false,
                'ambiguous_outcome' => $ambiguousOutcome,
                'started_at' => $startedAt,
                'timeout_at' => $timeoutAt,
                'completed_at' => $completedAt,
                'audit_evidence' => $canonicalAuditEvidence,
                'metadata' => $canonicalMetadata,
                'created_at' => $startedAt,
                'updated_at' => $completedAt ?? $timeoutAt ?? $startedAt,
            ];

            try {
                $this->database->connection()->table('sender_verification_operations')->insert($values);
            } catch (QueryException $exception) {
                $raceWinner = $this->database->connection()->table('sender_verification_operations')
                    ->where('workspace_id', $workspaceId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($raceWinner instanceof stdClass) {
                    $this->assertReplayMatches(
                        existing: $raceWinner,
                        operationType: $operationType,
                        operationState: $operationState,
                        outcomeClass: $outcomeClass,
                        requestFingerprint: $requestFingerprint,
                        providerOperationReference: $providerOperationReference,
                        ambiguousOutcome: $ambiguousOutcome,
                        canonicalAuditEvidence: $canonicalAuditEvidence,
                        canonicalMetadata: $canonicalMetadata,
                    );

                    return;
                }

                throw $exception;
            }
        });
    }

    private function assertDomainOwnedByWorkspace(string $workspaceId, string $senderDomainId): void
    {
        $owned = $this->database->connection()->table('sender_domains')
            ->where('workspace_id', $workspaceId)
            ->where('id', $senderDomainId)
            ->exists();

        if ($owned) {
            return;
        }

        $foreign = $this->database->connection()->table('sender_domains')
            ->where('id', $senderDomainId)
            ->exists();

        if ($foreign) {
            throw new AuthorizationException('Sender operation domain access denied.');
        }

        throw new InvalidArgumentException('Sender operation domain does not exist in this workspace.');
    }

    private function assertReplayMatches(
        stdClass $existing,
        string $operationType,
        string $operationState,
        string $outcomeClass,
        string $requestFingerprint,
        ?string $providerOperationReference,
        bool $ambiguousOutcome,
        string $canonicalAuditEvidence,
        string $canonicalMetadata,
    ): void {
        $storedAuditEvidence = $this->canonicalJson($this->decodeObjectPayload($existing->audit_evidence ?? null));
        $storedMetadata = $this->canonicalJson($this->decodeObjectPayload($existing->metadata ?? null));

        if (
            (string) ($existing->operation_type ?? '') !== $operationType
            || (string) ($existing->operation_state ?? '') !== $operationState
            || (string) ($existing->outcome_class ?? '') !== $outcomeClass
            || (string) ($existing->request_fingerprint ?? '') !== $requestFingerprint
            || ($existing->provider_operation_reference ?? null) !== $providerOperationReference
            || (bool) ($existing->ambiguous_outcome ?? false) !== $ambiguousOutcome
            || (bool) ($existing->production_activation_permitted ?? false)
            || (string) ($existing->mutation_mode ?? '') !== 'read_only'
            || $storedAuditEvidence !== $canonicalAuditEvidence
            || $storedMetadata !== $canonicalMetadata
        ) {
            throw new InvalidArgumentException('Sender operation idempotency key conflicts with a different request or outcome.');
        }
    }

    private function verificationFingerprint(SenderVerificationRequest $request): string
    {
        $evidence = array_map(
            static fn (AuthenticationEvidence $item): array => [
                'id' => $item->id,
                'dimension' => $item->dimension->value,
                'status' => $item->status->value,
                'version' => $item->evidenceVersion,
                'source_type' => $item->sourceType,
                'provider_key' => $item->providerKey,
                'public_material' => $item->publicMaterial,
                'redacted_evidence' => $item->redactedEvidence,
                'source_url' => $item->sourceUrl,
                'source_version' => $item->sourceVersion,
                'observed_at' => self::timestamp($item->observedAt),
                'fresh_until' => self::timestamp($item->freshUntil),
            ],
            $request->authenticationEvidence,
        );

        usort($evidence, static fn (array $left, array $right): int => strcmp(
            $left['dimension'].'|'.$left['version'].'|'.$left['id'],
            $right['dimension'].'|'.$right['version'].'|'.$right['id'],
        ));

        $policies = array_map(
            static fn (MailboxProviderPolicy $policy): array => [
                'id' => $policy->id,
                'provider_key' => $policy->providerKey,
                'policy_key' => $policy->policyKey,
                'policy_version' => $policy->policyVersion,
                'effective_from' => self::timestamp($policy->effectiveFrom),
                'effective_until' => self::timestamp($policy->effectiveUntil),
                'high_volume_threshold' => $policy->highVolumeThreshold,
                'threshold_unit' => $policy->thresholdUnit,
                'classification_inputs' => $policy->classificationInputs,
                'requirements' => $policy->requirements,
                'provenance_url' => $policy->provenanceUrl,
                'source_version' => $policy->sourceVersion,
                'observed_at' => self::timestamp($policy->observedAt),
                'fresh_until' => self::timestamp($policy->freshUntil),
            ],
            $request->providerPolicies,
        );

        usort($policies, static fn (array $left, array $right): int => strcmp(
            $left['provider_key'].'|'.$left['policy_key'].'|'.$left['policy_version'].'|'.$left['id'],
            $right['provider_key'].'|'.$right['policy_key'].'|'.$right['policy_version'].'|'.$right['id'],
        ));

        return hash('sha256', $this->canonicalJson([
            'operation_type' => 'verification',
            'operation_key' => $request->operationKey,
            'workspace_id' => $request->workspaceId,
            'sender_domain_id' => $request->senderDomainId,
            'provider_key' => $request->providerKey,
            'observation_outcome' => $request->observationOutcome->value,
            'observed_volume' => $request->observedVolume,
            'configured_high_volume' => $request->configuredHighVolume,
            'evaluated_at' => self::timestamp($request->evaluatedAt),
            'authentication_evidence' => $evidence,
            'provider_policies' => $policies,
        ]));
    }

    private function synchronizationFingerprint(SenderSynchronizationRequest $request): string
    {
        return hash('sha256', $this->canonicalJson([
            'operation_type' => 'synchronization',
            'operation_key' => $request->operationKey,
            'workspace_id' => $request->workspaceId,
            'sender_domain_id' => $request->senderDomainId,
            'provider_key' => $request->providerKey,
            'verification_operation_key' => $request->verification->operationKey,
            'verification_outcome' => $request->verification->observationOutcome->value,
            'verification_authentication_readiness' => $request->verification->authentication->readiness->value,
            'verification_provider_classification' => $request->verification->providerPolicy->classification->value,
            'verification_eligible' => $request->verification->eligibleForLaterSendingEvaluation,
            'provider_outcome' => $request->providerOutcome->value,
            'public_evidence' => $request->publicEvidence,
            'observed_at' => self::timestamp($request->observedAt),
            'provider_reference' => $request->providerReference,
            'source_version' => $request->sourceVersion,
        ]));
    }

    private function canonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Sender operation audit payload must be JSON-safe.', previous: $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function decodeObjectPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Stored sender operation audit payload is malformed.');
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored sender operation audit payload is malformed.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Stored sender operation audit payload is malformed.');
        }

        return $decoded;
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d\TH:i:s.uP');
    }
}
