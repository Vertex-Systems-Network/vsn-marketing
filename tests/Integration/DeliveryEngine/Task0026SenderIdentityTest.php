<?php

use App\Modules\DeliveryEngine\Application\SenderSync\SenderSynchronizationOutcome;
use App\Modules\DeliveryEngine\Application\SenderSync\SenderSynchronizationRequest;
use App\Modules\DeliveryEngine\Application\SenderSync\SynchronizeSenderDomain;
use App\Modules\DeliveryEngine\Application\SenderVerification\SenderVerificationObservationOutcome;
use App\Modules\DeliveryEngine\Application\SenderVerification\SenderVerificationRequest;
use App\Modules\DeliveryEngine\Application\SenderVerification\VerifySenderDomain;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDimension;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidence;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidenceEvaluator;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidenceStatus;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomain;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomainLifecycle;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomainName;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderEligibilityContext;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderIdentity;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderIdentityLifecycle;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderPurpose;
use App\Modules\DeliveryEngine\Infrastructure\SenderIdentity\DatabaseSenderIdentityRepository;
use App\Modules\DeliveryEngine\Infrastructure\SenderIdentity\DatabaseSenderOperationRecorder;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicy;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicyEvaluator;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0026 PostgreSQL certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0026 PostgreSQL certification requires the pgsql driver.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** @return array{organization_id: string, workspace_id: string} */
function task0026IntegrationWorkspace(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'TASK-0026 '.$suffix,
        'slug' => 'task0026-'.$suffix.'-'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0026 Workspace '.$suffix,
        'slug' => 'task0026-workspace-'.$suffix.'-'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'organization_id' => $organizationId,
        'workspace_id' => $workspaceId,
    ];
}

function task0026IntegrationRepository(): DatabaseSenderIdentityRepository
{
    return new DatabaseSenderIdentityRepository(app(DatabaseManager::class));
}

function task0026IntegrationOperationRecorder(): DatabaseSenderOperationRecorder
{
    return new DatabaseSenderOperationRecorder(app(DatabaseManager::class));
}

function task0026IntegrationDomain(
    string $workspaceId,
    string $name,
    ?string $id = null,
    ?string $idempotencyKey = null,
): SenderDomain {
    $now = new DateTimeImmutable('2026-09-16T13:00:00+00:00');

    return new SenderDomain(
        id: $id ?? (string) Str::uuid(),
        workspaceId: $workspaceId,
        domain: new SenderDomainName($name),
        lifecycle: SenderDomainLifecycle::Registered,
        idempotencyKey: $idempotencyKey ?? 'domain-'.Str::lower(Str::random(12)),
        metadata: ['source' => 'task0026-integration'],
        createdAt: $now,
        updatedAt: $now,
    );
}

function task0026IntegrationEligibility(): SenderEligibilityContext
{
    return new SenderEligibilityContext(
        purpose: SenderPurpose::Marketing,
        providerKey: 'integration-provider',
        policyKey: 'bulk-sender-policy',
        policyVersion: '2026-09',
        policyContext: ['source' => 'official-integration-fixture'],
    );
}

function task0026IntegrationIdentity(
    string $workspaceId,
    SenderDomain $domain,
    ?string $id = null,
    ?string $idempotencyKey = null,
): SenderIdentity {
    $now = new DateTimeImmutable('2026-09-16T13:01:00+00:00');

    return new SenderIdentity(
        id: $id ?? (string) Str::uuid(),
        workspaceId: $workspaceId,
        senderDomainId: $domain->id,
        domain: $domain->domain,
        localPart: 'news',
        displayName: 'TASK-0026 Sender',
        replyToAddress: 'reply@'.$domain->domain->value,
        lifecycle: SenderIdentityLifecycle::Registered,
        eligibility: task0026IntegrationEligibility(),
        providerConnectionId: null,
        idempotencyKey: $idempotencyKey ?? 'identity-'.Str::lower(Str::random(12)),
        metadata: ['source' => 'task0026-integration'],
        createdAt: $now,
        updatedAt: $now,
    );
}

function task0026IntegrationEvidence(
    string $workspaceId,
    string $senderDomainId,
    AuthenticationDimension $dimension,
    AuthenticationEvidenceStatus $status = AuthenticationEvidenceStatus::Pass,
    string $version = 'v1',
    ?DateTimeImmutable $observedAt = null,
    ?DateTimeImmutable $freshUntil = null,
): AuthenticationEvidence {
    $observedAt ??= new DateTimeImmutable('2026-09-16T13:02:00+00:00');
    $freshUntil ??= new DateTimeImmutable('2026-09-17T13:02:00+00:00');

    return new AuthenticationEvidence(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        senderDomainId: $senderDomainId,
        dimension: $dimension,
        status: $status,
        evidenceVersion: $version,
        sourceType: 'integration-probe',
        providerKey: 'integration-provider',
        publicMaterial: ['dimension' => $dimension->value],
        redactedEvidence: ['result' => $status->value],
        sourceUrl: 'https://example.test/evidence/'.$dimension->value,
        sourceVersion: 'source-2026-09',
        observedAt: $observedAt,
        freshUntil: $freshUntil,
        recordedAt: $observedAt->modify('+1 minute'),
    );
}

/** @return list<AuthenticationEvidence> */
function task0026IntegrationReadyEvidence(string $workspaceId, string $senderDomainId): array
{
    return array_map(
        static fn (AuthenticationDimension $dimension): AuthenticationEvidence => task0026IntegrationEvidence(
            workspaceId: $workspaceId,
            senderDomainId: $senderDomainId,
            dimension: $dimension,
        ),
        AuthenticationDimension::cases(),
    );
}

function task0026IntegrationProviderPolicy(string $workspaceId): MailboxProviderPolicy
{
    return new MailboxProviderPolicy(
        id: (string) Str::uuid(),
        workspaceId: $workspaceId,
        providerKey: 'integration-provider',
        policyKey: 'bulk-sender-policy',
        policyVersion: '2026-09',
        effectiveFrom: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        effectiveUntil: null,
        highVolumeThreshold: 1000,
        thresholdUnit: 'messages_per_day',
        classificationInputs: ['source' => 'official'],
        requirements: ['authentication' => 'required'],
        provenanceUrl: 'https://example.test/provider-policy',
        sourceVersion: 'policy-source-2026-09',
        observedAt: new DateTimeImmutable('2026-09-15T00:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-10-15T00:00:00+00:00'),
    );
}

it('certifies workspace isolation and replay-safe sender domain and identity persistence on PostgreSQL', function () {
    $inside = task0026IntegrationWorkspace('inside');
    $outside = task0026IntegrationWorkspace('outside');
    $repository = task0026IntegrationRepository();

    $domain = task0026IntegrationDomain(
        workspaceId: $inside['workspace_id'],
        name: 'Example.COM.',
        idempotencyKey: 'domain-replay-key',
    );

    $firstDomain = $repository->createDomain($domain);
    $replayedDomain = $repository->createDomain($domain);

    expect($replayedDomain->id)->toBe($firstDomain->id)
        ->and((string) $replayedDomain->domain)->toBe('example.com')
        ->and(DB::table('sender_domains')->where('workspace_id', $inside['workspace_id'])->count())->toBe(1);

    expect(fn () => $repository->createDomain(task0026IntegrationDomain(
        workspaceId: $inside['workspace_id'],
        name: 'different.example',
        idempotencyKey: 'domain-replay-key',
    )))->toThrow(InvalidArgumentException::class, 'idempotency key conflicts');

    $outsideDomain = task0026IntegrationDomain($outside['workspace_id'], 'outside.example');
    $repository->createDomain($outsideDomain);

    $foreignUpdate = new SenderDomain(
        id: $outsideDomain->id,
        workspaceId: $inside['workspace_id'],
        domain: $outsideDomain->domain,
        lifecycle: SenderDomainLifecycle::Managed,
        idempotencyKey: $outsideDomain->idempotencyKey,
        metadata: $outsideDomain->metadata,
        createdAt: $outsideDomain->createdAt,
        updatedAt: $outsideDomain->updatedAt->modify('+1 minute'),
    );

    expect(fn () => $repository->updateDomain($foreignUpdate))
        ->toThrow(AuthorizationException::class, 'Sender domain access denied');

    $identity = task0026IntegrationIdentity(
        workspaceId: $inside['workspace_id'],
        domain: $domain,
        idempotencyKey: 'identity-replay-key',
    );
    $firstIdentity = $repository->createIdentity($identity);
    $replayedIdentity = $repository->createIdentity($identity);

    expect($replayedIdentity->id)->toBe($firstIdentity->id)
        ->and($repository->findIdentityByEmail($inside['workspace_id'], ' NEWS@EXAMPLE.COM ')?->id)->toBe($identity->id)
        ->and(DB::table('sender_identities')->where('workspace_id', $inside['workspace_id'])->count())->toBe(1);

    $foreignDomainIdentity = task0026IntegrationIdentity($inside['workspace_id'], $outsideDomain);

    expect(fn () => $repository->createIdentity($foreignDomainIdentity))
        ->toThrow(AuthorizationException::class, 'Sender domain access denied');
});

it('certifies authentication evidence is versioned immutable and fail-closed across workspaces on PostgreSQL', function () {
    $inside = task0026IntegrationWorkspace('evidence-inside');
    $outside = task0026IntegrationWorkspace('evidence-outside');
    $repository = task0026IntegrationRepository();
    $domain = task0026IntegrationDomain($inside['workspace_id'], 'evidence.example');
    $repository->createDomain($domain);

    $evidence = task0026IntegrationEvidence(
        workspaceId: $inside['workspace_id'],
        senderDomainId: $domain->id,
        dimension: AuthenticationDimension::Dkim,
    );

    $first = $repository->appendAuthenticationEvidence($evidence);
    $replayed = $repository->appendAuthenticationEvidence($evidence);

    expect($replayed->id)->toBe($first->id)
        ->and($repository->authenticationEvidenceForDimension(
            $inside['workspace_id'],
            $domain->id,
            AuthenticationDimension::Dkim,
        ))->toHaveCount(1)
        ->and(DB::table('sender_authentication_evidence')->count())->toBe(1);

    $contradictoryReplay = task0026IntegrationEvidence(
        workspaceId: $inside['workspace_id'],
        senderDomainId: $domain->id,
        dimension: AuthenticationDimension::Dkim,
        status: AuthenticationEvidenceStatus::Fail,
        version: $evidence->evidenceVersion,
    );

    expect(fn () => $repository->appendAuthenticationEvidence($contradictoryReplay))
        ->toThrow(InvalidArgumentException::class);

    $foreignEvidence = task0026IntegrationEvidence(
        workspaceId: $outside['workspace_id'],
        senderDomainId: $domain->id,
        dimension: AuthenticationDimension::Spf,
    );

    expect(fn () => $repository->appendAuthenticationEvidence($foreignEvidence))
        ->toThrow(AuthorizationException::class);
});

it('certifies verification and synchronization operations are replay-safe, auditable, ambiguity-aware and never activate production sending', function () {
    $fixture = task0026IntegrationWorkspace('sync');
    $workspaceId = $fixture['workspace_id'];
    $repository = task0026IntegrationRepository();
    $recorder = task0026IntegrationOperationRecorder();
    $domain = task0026IntegrationDomain($workspaceId, 'sync.example', idempotencyKey: 'sync-domain-record');
    $repository->createDomain($domain);
    $domainId = $domain->id;
    $evaluatedAt = new DateTimeImmutable('2026-09-16T13:10:00+00:00');
    $verifier = new VerifySenderDomain(
        new AuthenticationEvidenceEvaluator,
        new MailboxProviderPolicyEvaluator,
    );
    $verificationRequest = new SenderVerificationRequest(
        operationKey: 'verify-sync-domain',
        workspaceId: $workspaceId,
        senderDomainId: $domainId,
        providerKey: 'integration-provider',
        authenticationEvidence: task0026IntegrationReadyEvidence($workspaceId, $domainId),
        providerPolicies: [task0026IntegrationProviderPolicy($workspaceId)],
        evaluatedAt: $evaluatedAt,
        observationOutcome: SenderVerificationObservationOutcome::Completed,
        observedVolume: 1500,
    );
    $verification = $verifier->handle($verificationRequest);

    expect($verification->eligibleForLaterSendingEvaluation)->toBeTrue()
        ->and($verification->productionActivationAllowed)->toBeFalse();

    $recorder->recordVerification($verificationRequest, $verification);
    $recorder->recordVerification($verificationRequest, $verification);

    $verificationRow = DB::table('sender_verification_operations')
        ->where('workspace_id', $workspaceId)
        ->where('idempotency_key', $verificationRequest->operationKey)
        ->first();

    expect($verificationRow)->not->toBeNull()
        ->and(DB::table('sender_verification_operations')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $verificationRequest->operationKey)
            ->count())->toBe(1)
        ->and($verificationRow->operation_type)->toBe('verification')
        ->and($verificationRow->operation_state)->toBe('completed')
        ->and($verificationRow->outcome_class)->toBe('completed')
        ->and($verificationRow->mutation_mode)->toBe('read_only')
        ->and((bool) $verificationRow->production_activation_permitted)->toBeFalse()
        ->and((bool) $verificationRow->ambiguous_outcome)->toBeFalse();

    $verificationTimeoutRequest = new SenderVerificationRequest(
        operationKey: 'verify-sync-domain-timeout',
        workspaceId: $workspaceId,
        senderDomainId: $domainId,
        providerKey: 'integration-provider',
        authenticationEvidence: $verificationRequest->authenticationEvidence,
        providerPolicies: $verificationRequest->providerPolicies,
        evaluatedAt: $evaluatedAt->modify('+30 seconds'),
        observationOutcome: SenderVerificationObservationOutcome::Timeout,
        observedVolume: 1500,
    );
    $verificationTimeout = $verifier->handle($verificationTimeoutRequest);
    $recorder->recordVerification($verificationTimeoutRequest, $verificationTimeout);

    $verificationTimeoutRow = DB::table('sender_verification_operations')
        ->where('workspace_id', $workspaceId)
        ->where('idempotency_key', $verificationTimeoutRequest->operationKey)
        ->first();

    expect($verificationTimeoutRow)->not->toBeNull()
        ->and($verificationTimeoutRow->operation_state)->toBe('timed_out')
        ->and($verificationTimeoutRow->outcome_class)->toBe('timeout')
        ->and($verificationTimeoutRow->timeout_at)->not->toBeNull()
        ->and($verificationTimeoutRow->completed_at)->toBeNull()
        ->and((bool) $verificationTimeoutRow->production_activation_permitted)->toBeFalse();

    $synchronizer = new SynchronizeSenderDomain;
    $confirmedRequest = new SenderSynchronizationRequest(
        operationKey: 'sync-domain-operation',
        workspaceId: $workspaceId,
        senderDomainId: $domainId,
        providerKey: 'integration-provider',
        verification: $verification,
        providerOutcome: SenderSynchronizationOutcome::Confirmed,
        publicEvidence: ['provider_status' => 'confirmed'],
        observedAt: $evaluatedAt->modify('+1 minute'),
        providerReference: 'provider-operation-123',
        sourceVersion: 'sync-source-v1',
    );

    $first = $synchronizer->handle($confirmedRequest);
    $replayed = $synchronizer->handle($confirmedRequest, $first);

    expect($replayed)->toBe($first)
        ->and($first->eligibleForLaterSendingEvaluation)->toBeTrue()
        ->and($first->reconciliationRequired)->toBeFalse()
        ->and($first->productionActivationAllowed)->toBeFalse();

    $recorder->recordSynchronization($confirmedRequest, $first);
    $recorder->recordSynchronization($confirmedRequest, $replayed);

    $confirmedRow = DB::table('sender_verification_operations')
        ->where('workspace_id', $workspaceId)
        ->where('idempotency_key', $confirmedRequest->operationKey)
        ->first();

    expect($confirmedRow)->not->toBeNull()
        ->and(DB::table('sender_verification_operations')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $confirmedRequest->operationKey)
            ->count())->toBe(1)
        ->and($confirmedRow->operation_type)->toBe('synchronization')
        ->and($confirmedRow->outcome_class)->toBe('confirmed')
        ->and($confirmedRow->provider_operation_reference)->toBe('provider-operation-123')
        ->and($confirmedRow->mutation_mode)->toBe('read_only')
        ->and((bool) $confirmedRow->production_activation_permitted)->toBeFalse();

    $ambiguousRequest = new SenderSynchronizationRequest(
        operationKey: 'sync-domain-ambiguous',
        workspaceId: $workspaceId,
        senderDomainId: $domainId,
        providerKey: 'integration-provider',
        verification: $verification,
        providerOutcome: SenderSynchronizationOutcome::Ambiguous,
        publicEvidence: ['provider_status' => 'unknown_after_timeout'],
        observedAt: $evaluatedAt->modify('+2 minutes'),
        providerReference: null,
        sourceVersion: 'sync-source-v1',
    );
    $ambiguous = $synchronizer->handle($ambiguousRequest);

    expect($ambiguous->providerOutcome)->toBe(SenderSynchronizationOutcome::Ambiguous)
        ->and($ambiguous->reconciliationRequired)->toBeTrue()
        ->and($ambiguous->eligibleForLaterSendingEvaluation)->toBeFalse()
        ->and($ambiguous->productionActivationAllowed)->toBeFalse();

    $recorder->recordSynchronization($ambiguousRequest, $ambiguous);

    $ambiguousRow = DB::table('sender_verification_operations')
        ->where('workspace_id', $workspaceId)
        ->where('idempotency_key', $ambiguousRequest->operationKey)
        ->first();

    expect($ambiguousRow)->not->toBeNull()
        ->and($ambiguousRow->operation_state)->toBe('completed')
        ->and($ambiguousRow->outcome_class)->toBe('ambiguous')
        ->and((bool) $ambiguousRow->ambiguous_outcome)->toBeTrue()
        ->and((bool) $ambiguousRow->production_activation_permitted)->toBeFalse();

    $timeoutRequest = new SenderSynchronizationRequest(
        operationKey: 'sync-domain-timeout',
        workspaceId: $workspaceId,
        senderDomainId: $domainId,
        providerKey: 'integration-provider',
        verification: $verification,
        providerOutcome: SenderSynchronizationOutcome::Timeout,
        publicEvidence: ['provider_status' => 'timeout'],
        observedAt: $evaluatedAt->modify('+3 minutes'),
        providerReference: null,
        sourceVersion: 'sync-source-v1',
    );
    $timeout = $synchronizer->handle($timeoutRequest);
    $recorder->recordSynchronization($timeoutRequest, $timeout);

    $timeoutRow = DB::table('sender_verification_operations')
        ->where('workspace_id', $workspaceId)
        ->where('idempotency_key', $timeoutRequest->operationKey)
        ->first();

    expect($timeoutRow)->not->toBeNull()
        ->and($timeoutRow->operation_state)->toBe('timed_out')
        ->and($timeoutRow->outcome_class)->toBe('timeout')
        ->and($timeoutRow->timeout_at)->not->toBeNull()
        ->and($timeoutRow->completed_at)->toBeNull()
        ->and((bool) $timeoutRow->production_activation_permitted)->toBeFalse();

    $changedReplay = new SenderSynchronizationRequest(
        operationKey: $confirmedRequest->operationKey,
        workspaceId: $workspaceId,
        senderDomainId: $domainId,
        providerKey: 'integration-provider',
        verification: $verification,
        providerOutcome: SenderSynchronizationOutcome::Timeout,
        publicEvidence: ['provider_status' => 'timeout'],
        observedAt: $confirmedRequest->observedAt,
        providerReference: $confirmedRequest->providerReference,
        sourceVersion: $confirmedRequest->sourceVersion,
    );

    expect(fn () => $synchronizer->handle($changedReplay, $first))
        ->toThrow(InvalidArgumentException::class, 'conflicts with a different replay outcome');

    $changedReplayResult = $synchronizer->handle($changedReplay);

    expect(fn () => $recorder->recordSynchronization($changedReplay, $changedReplayResult))
        ->toThrow(InvalidArgumentException::class, 'idempotency key conflicts');

    expect(DB::table('sender_verification_operations')
        ->where('workspace_id', $workspaceId)
        ->where('idempotency_key', $confirmedRequest->operationKey)
        ->count())->toBe(1);
});
