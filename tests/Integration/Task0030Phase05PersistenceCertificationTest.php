<?php

use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use App\Modules\Consent\Infrastructure\Suppression\DatabaseSuppressionRepository;
use App\Modules\Consent\Infrastructure\Unsubscribe\DatabaseUnsubscribeTokenRepository;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\DeliveryEngine\Application\Deliverability\BuildRemediationRecommendations;
use App\Modules\DeliveryEngine\Application\Deliverability\EvaluateDeliverabilityDiagnostics;
use App\Modules\DeliveryEngine\Application\Deliverability\RemediationRecommendation;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSafeSendingEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\SafeSendingEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use App\Modules\DeliveryEngine\Application\Frequency\FrequencyEvaluationResult;
use App\Modules\DeliveryEngine\Application\Reputation\ReputationHealthEvaluationResult;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilitySignalKind;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDecision;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationReadiness;
use App\Modules\DeliveryEngine\Infrastructure\Deliverability\DatabaseDeliverabilityObservationRepository;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0030 PHASE-05 PostgreSQL certification.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0030 PHASE-05 certification requires the pgsql driver.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** @return array{organization_id:string,workspace_id:string,brand_id:string,context:TenantContext} */
function task0030PersistenceTenant(string $suffix, ?string $organizationId = null): array
{
    $organizationId ??= (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'name' => 'TASK-0030 '.$suffix,
            'slug' => 'task0030-org-'.$suffix.'-'.Str::lower(Str::random(8)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'TASK-0030 Workspace '.$suffix,
        'slug' => 'task0030-workspace-'.$suffix.'-'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'TASK-0030 Brand '.$suffix,
        'slug' => 'task0030-brand-'.$suffix.'-'.Str::lower(Str::random(8)),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'organization_id' => $organizationId,
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'task0030-'.$suffix,
        ),
    ];
}

function task0030PersistenceSuppression(
    string $workspaceId,
    string $contactId,
    string $idempotencyKey,
    ?string $id = null,
    array $evidence = ['transport' => 'rfc8058', 'result' => 'accepted'],
): SuppressionRecord {
    $at = new DateTimeImmutable('2026-09-18T12:00:00+00:00');

    return new SuppressionRecord(
        id: $id ?? (string) Str::uuid(),
        workspaceId: $workspaceId,
        contactId: $contactId,
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Unsubscribe,
        sourceType: 'rfc8058_one_click',
        sourceVersion: 'RFC8058',
        providerKey: null,
        idempotencyKey: $idempotencyKey,
        observedAt: $at,
        effectiveAt: $at,
        freshUntil: null,
        immutableEvidence: $evidence,
    );
}

function task0030PersistenceObservation(
    string $workspaceId,
    string $id,
    string $replayKey,
    string $signalValue = '0.001',
): DeliverabilityObservation {
    return new DeliverabilityObservation(
        id: $id,
        workspaceId: $workspaceId,
        providerKey: 'provider-a',
        source: 'provider-feedback-feed',
        version: 'provider-a-2026-09-v1',
        messagePurpose: 'marketing',
        kind: DeliverabilitySignalKind::Complaint,
        signalKey: 'complaint_rate',
        signalValue: $signalValue,
        provenanceReference: 'provider-doc://provider-a/feedback/2026-09',
        replayKey: $replayKey,
        effectiveAt: new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
        observedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
        recordedAt: new DateTimeImmutable('2026-09-18T11:01:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-09-18T13:00:00+00:00'),
        trusted: true,
    );
}

function task0030PersistenceSafeSending(
    bool $suppressed,
    string $workspaceId,
): \App\Modules\DeliveryEngine\Application\Eligibility\SafeSendingEligibilityResult {
    $at = new DateTimeImmutable('2026-09-18T12:00:00+00:00');
    $context = new EligibilityContext(
        messagePurpose: MessageIntentType::Marketing,
        jurisdiction: 'GB',
        subscriberType: 'individual',
        solicitationBasis: 'direct_marketing',
        relationshipBasis: 'existing_customer',
        policyBasis: PolicyBasisType::ExplicitConsent,
        policyBasisEvidencePresent: true,
        jurisdictionPolicyOutcome: EligibilityOutcome::Allow,
        policyVersion: 'gb-pecr-2026-02',
        policyEffectiveAt: new DateTimeImmutable('2026-02-05T00:00:00+00:00'),
        providerKey: 'provider-a',
        providerContextKnown: true,
        suppressionApplies: false,
        objectionApplies: false,
    );
    $request = new SafeSendingEligibilityRequest(
        workspaceId: $workspaceId,
        suppressionAwareRequest: new SuppressionAwareEligibilityRequest(
            policyContext: $context,
            canonicalSuppressionApplies: $suppressed,
            canonicalObjectionApplies: false,
        ),
        senderAuthentication: new AuthenticationDecision(
            workspaceId: $workspaceId,
            senderDomainId: 'sender-domain-a',
            readiness: AuthenticationReadiness::Ready,
            reasons: ['authentication_evaluated'],
            evidenceVersions: ['spf-v1', 'dkim-v1', 'dmarc-v1'],
            decidedAt: $at,
        ),
        frequencyEvaluation: new FrequencyEvaluationResult(
            outcome: EligibilityOutcome::Allow,
            reasons: ['frequency_within_limit'],
            evaluatedAt: $at,
            replay: false,
            currentCount: 0,
            nextCount: 1,
            windowStart: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
            windowEnd: new DateTimeImmutable('2026-09-18T13:00:00+00:00'),
        ),
        reputationEvaluation: new ReputationHealthEvaluationResult(
            outcome: EligibilityOutcome::Allow,
            reasons: ['reputation_health_healthy'],
            evaluatedAt: $at,
            providerKey: 'provider-a',
            evidenceIds: ['task0030-persisted-reputation'],
        ),
        evaluatedAt: $at,
    );

    return (new EvaluateSafeSendingEligibility(
        new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility),
    ))->evaluate($request);
}

it('certifies persisted one-click scope suppression and deliverability evidence remain replay safe while suppression stays authoritative', function () {
    $fixture = task0030PersistenceTenant('authority');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Authority');
    $tokenRepository = app(DatabaseUnsubscribeTokenRepository::class);
    $suppressionRepository = app(DatabaseSuppressionRepository::class);
    $observationRepository = new DatabaseDeliverabilityObservationRepository(app(DatabaseManager::class));

    $rawToken = str_repeat('p', 43);
    $token = new OpaqueUnsubscribeToken(
        value: $rawToken,
        scope: new UnsubscribeScope(
            workspaceId: $fixture['workspace_id'],
            contactId: $contact->id,
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'newsletter',
        ),
        issuedAt: new DateTimeImmutable('2026-09-18T11:30:00+00:00'),
        expiresAt: new DateTimeImmutable('2026-10-18T11:30:00+00:00'),
    );
    $tokenId = (string) Str::uuid();
    $tokenRepository->persist($tokenId, $token, ['issuer' => 'task0030']);
    $tokenRepository->persist($tokenId, $token, ['issuer' => 'task0030']);

    $suppression = task0030PersistenceSuppression(
        $fixture['workspace_id'],
        $contact->id,
        'rfc8058:'.$token->digest,
    );
    $suppressionRepository->appendSuppression($suppression);
    $suppressionRepository->appendSuppression($suppression);

    $observation = task0030PersistenceObservation(
        $fixture['workspace_id'],
        'task0030-observation-authority',
        'task0030-replay-authority',
        '0.0001',
    );
    $observationRepository->append($observation);
    $observationRepository->append($observation);

    $suppressed = $suppressionRepository->isSuppressed(
        $fixture['workspace_id'],
        $contact->id,
        'email',
        'marketing',
        new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
    );
    $safeSending = task0030PersistenceSafeSending($suppressed, $fixture['workspace_id']);
    $storedObservations = $observationRepository->observations(
        $fixture['workspace_id'],
        'provider-a',
        'marketing',
    );
    $diagnostic = (new EvaluateDeliverabilityDiagnostics)->evaluate(
        $fixture['workspace_id'],
        'provider-a',
        'marketing',
        $storedObservations,
        new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
    );
    $recommendation = (new BuildRemediationRecommendations)->build($diagnostic)[0];

    expect($tokenRepository->resolve($rawToken)?->digest)->toBe($token->digest)
        ->and(DB::table('unsubscribe_token_scopes')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1)
        ->and(DB::table('suppression_records')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1)
        ->and(DB::table('deliverability_observations')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1)
        ->and($suppressed)->toBeTrue()
        ->and($safeSending->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($safeSending->reasons)->toContain('canonical_suppression_applies')
        ->and($recommendation->executionMode)->toBe(RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY)
        ->and($recommendation->rationale)->toContain('does not establish provider health or permission to send');
});

it('fails closed across workspace boundaries for unsubscribe suppression and deliverability evidence', function () {
    $left = task0030PersistenceTenant('left');
    $right = task0030PersistenceTenant('right', $left['organization_id']);
    $leftContact = app(CreateContact::class)->handle($left['context'], firstName: 'Left');
    $rightContact = app(CreateContact::class)->handle($right['context'], firstName: 'Right');
    $tokenRepository = app(DatabaseUnsubscribeTokenRepository::class);
    $suppressionRepository = app(DatabaseSuppressionRepository::class);
    $observationRepository = new DatabaseDeliverabilityObservationRepository(app(DatabaseManager::class));

    $foreignToken = new OpaqueUnsubscribeToken(
        value: str_repeat('x', 43),
        scope: new UnsubscribeScope(
            workspaceId: $left['workspace_id'],
            contactId: $rightContact->id,
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'newsletter',
        ),
        issuedAt: new DateTimeImmutable('2026-09-18T11:30:00+00:00'),
    );

    expect(fn () => $tokenRepository->persist((string) Str::uuid(), $foreignToken))
        ->toThrow(AuthorizationException::class, 'Contact access denied');

    expect(fn () => $suppressionRepository->appendSuppression(task0030PersistenceSuppression(
        $left['workspace_id'],
        $rightContact->id,
        'task0030-foreign-suppression',
    )))->toThrow(AuthorizationException::class, 'Contact access denied');

    $observationRepository->append(task0030PersistenceObservation(
        $left['workspace_id'],
        'task0030-shared-observation-id',
        'task0030-left-replay',
    ));

    expect(fn () => $observationRepository->append(task0030PersistenceObservation(
        $right['workspace_id'],
        'task0030-shared-observation-id',
        'task0030-right-replay',
    )))->toThrow(AuthorizationException::class, 'Deliverability observation access denied');

    expect(DB::table('unsubscribe_token_scopes')->where('workspace_id', $right['workspace_id'])->count())->toBe(0)
        ->and(DB::table('suppression_records')->where('workspace_id', $right['workspace_id'])->count())->toBe(0)
        ->and($observationRepository->observations($right['workspace_id']))->toBe([])
        ->and($observationRepository->observations($left['workspace_id']))->toHaveCount(1)
        ->and($leftContact->workspaceId)->toBe($left['workspace_id']);
});

it('rejects conflicting replay evidence without replacing canonical persisted evidence', function () {
    $fixture = task0030PersistenceTenant('conflicts');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Conflict');
    $tokenRepository = app(DatabaseUnsubscribeTokenRepository::class);
    $suppressionRepository = app(DatabaseSuppressionRepository::class);
    $observationRepository = new DatabaseDeliverabilityObservationRepository(app(DatabaseManager::class));

    $rawToken = str_repeat('c', 43);
    $token = new OpaqueUnsubscribeToken(
        value: $rawToken,
        scope: new UnsubscribeScope(
            workspaceId: $fixture['workspace_id'],
            contactId: $contact->id,
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'newsletter',
        ),
        issuedAt: new DateTimeImmutable('2026-09-18T11:30:00+00:00'),
    );
    $tokenId = (string) Str::uuid();
    $tokenRepository->persist($tokenId, $token, ['issuer' => 'task0030']);

    expect(fn () => $tokenRepository->persist($tokenId, $token, ['issuer' => 'changed']))
        ->toThrow(InvalidArgumentException::class, 'replay conflicts with stored scope evidence');

    $suppressionId = (string) Str::uuid();
    $suppression = task0030PersistenceSuppression(
        $fixture['workspace_id'],
        $contact->id,
        'task0030-stable-suppression-replay',
        $suppressionId,
    );
    $suppressionRepository->appendSuppression($suppression);

    expect(fn () => $suppressionRepository->appendSuppression(task0030PersistenceSuppression(
        $fixture['workspace_id'],
        $contact->id,
        'task0030-stable-suppression-replay',
        $suppressionId,
        ['transport' => 'rfc8058', 'result' => 'conflicting'],
    )))->toThrow(InvalidArgumentException::class, 'idempotency key conflicts with different evidence');

    $observationRepository->append(task0030PersistenceObservation(
        $fixture['workspace_id'],
        'task0030-stable-observation',
        'task0030-stable-observation-replay',
        '0.001',
    ));

    expect(fn () => $observationRepository->append(task0030PersistenceObservation(
        $fixture['workspace_id'],
        'task0030-stable-observation',
        'task0030-stable-observation-replay',
        '0.999',
    )))->toThrow(InvalidArgumentException::class, 'replay key conflicts with different evidence');

    $storedObservation = $observationRepository->observations($fixture['workspace_id'])[0];
    $storedSuppression = DB::table('suppression_records')->where('id', $suppressionId)->first();

    expect(DB::table('unsubscribe_token_scopes')->where('id', $tokenId)->count())->toBe(1)
        ->and(DB::table('suppression_records')->where('id', $suppressionId)->count())->toBe(1)
        ->and($storedObservation->signalValue)->toBe('0.001')
        ->and((string) $storedSuppression->evidence_fingerprint)->toBe($suppression->evidenceFingerprint);
});

it('keeps canonical suppression append only at the database boundary and never stores raw one-click token material', function () {
    $fixture = task0030PersistenceTenant('append-only');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Append Only');
    $tokenRepository = app(DatabaseUnsubscribeTokenRepository::class);
    $suppressionRepository = app(DatabaseSuppressionRepository::class);

    $rawToken = str_repeat('z', 43);
    $token = new OpaqueUnsubscribeToken(
        value: $rawToken,
        scope: new UnsubscribeScope(
            workspaceId: $fixture['workspace_id'],
            contactId: $contact->id,
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'newsletter',
        ),
        issuedAt: new DateTimeImmutable('2026-09-18T11:30:00+00:00'),
    );
    $tokenId = (string) Str::uuid();
    $tokenRepository->persist($tokenId, $token, ['issuer' => 'task0030']);

    $suppression = task0030PersistenceSuppression(
        $fixture['workspace_id'],
        $contact->id,
        'task0030-append-only-suppression',
    );
    $suppressionRepository->appendSuppression($suppression);

    $storedToken = DB::table('unsubscribe_token_scopes')->where('id', $tokenId)->first();
    expect((string) $storedToken->token_digest)->toBe($token->digest)
        ->and(json_encode($storedToken, JSON_THROW_ON_ERROR))->not->toContain($rawToken);

    expect(fn () => DB::transaction(
        fn () => DB::table('suppression_records')
            ->where('id', $suppression->id)
            ->update(['purpose' => 'transactional']),
    ))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(
        fn () => DB::table('suppression_records')
            ->where('id', $suppression->id)
            ->delete(),
    ))->toThrow(QueryException::class);

    expect($suppressionRepository->isSuppressed(
        $fixture['workspace_id'],
        $contact->id,
        'email',
        'marketing',
        new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
    ))->toBeTrue();
});
