<?php

use App\Modules\DeliveryEngine\Application\Deliverability\BuildRemediationRecommendations;
use App\Modules\DeliveryEngine\Application\Deliverability\DeliverabilityDiagnosticResult;
use App\Modules\DeliveryEngine\Application\Deliverability\RemediationRecommendation;

function task0029DiagnosticResultForRemediation(
    string $status = DeliverabilityDiagnosticResult::STATUS_REVIEW,
    array $reasons = ['deliverability_evidence_stale'],
    array $evidenceIds = ['evidence-b', 'evidence-a'],
    array $signalKinds = ['complaint'],
): DeliverabilityDiagnosticResult {
    return new DeliverabilityDiagnosticResult(
        status: $status,
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        reasons: $reasons,
        evidenceIds: $evidenceIds,
        signalKinds: $signalKinds,
        evaluatedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );
}

it('builds an unknown-evidence refresh proposal without creating permission or execution authority', function () {
    $recommendation = (new BuildRemediationRecommendations)->build(
        task0029DiagnosticResultForRemediation(
            status: DeliverabilityDiagnosticResult::STATUS_UNKNOWN,
            reasons: ['deliverability_evidence_missing'],
            evidenceIds: [],
            signalKinds: [],
        ),
    )[0];

    expect($recommendation->code)->toBe('refresh_deliverability_evidence')
        ->and($recommendation->scope)->toBe('evidence')
        ->and($recommendation->riskLevel)->toBe(RemediationRecommendation::RISK_LOW)
        ->and($recommendation->requiresHumanApproval)->toBeFalse()
        ->and($recommendation->requiresPolicyApproval)->toBeFalse()
        ->and($recommendation->executionMode)->toBe(RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY)
        ->and(method_exists($recommendation, 'execute'))->toBeFalse()
        ->and(property_exists($recommendation, 'allowed'))->toBeFalse()
        ->and(property_exists($recommendation, 'outcome'))->toBeFalse();
});

it('builds a review proposal with stable evidence references and diagnostic reason codes', function () {
    $recommendation = (new BuildRemediationRecommendations)->build(
        task0029DiagnosticResultForRemediation(),
    )[0];

    expect($recommendation->code)->toBe('investigate_deliverability_evidence')
        ->and($recommendation->riskLevel)->toBe(RemediationRecommendation::RISK_MEDIUM)
        ->and($recommendation->evidenceIds)->toBe(['evidence-a', 'evidence-b'])
        ->and($recommendation->rationale)->toContain('deliverability_evidence_stale')
        ->and($recommendation->workspaceId)->toBe('workspace-a')
        ->and($recommendation->providerKey)->toBe('provider-a')
        ->and($recommendation->messagePurpose)->toBe('marketing');
});

it('does not reinterpret observed evidence as healthy or authorized', function () {
    $recommendation = (new BuildRemediationRecommendations)->build(
        task0029DiagnosticResultForRemediation(
            status: DeliverabilityDiagnosticResult::STATUS_OBSERVED,
            reasons: ['deliverability_evidence_observed'],
        ),
    )[0];

    expect($recommendation->code)->toBe('continue_provider_observation')
        ->and($recommendation->scope)->toBe('monitoring')
        ->and($recommendation->rationale)->toContain('does not establish provider health or permission to send')
        ->and($recommendation->riskLevel)->toBe(RemediationRecommendation::RISK_LOW);
});

it('produces deterministic proposal identities for identical diagnostics', function () {
    $builder = new BuildRemediationRecommendations;
    $diagnostic = task0029DiagnosticResultForRemediation();

    $first = $builder->build($diagnostic)[0];
    $second = $builder->build($diagnostic)[0];

    expect($first->id)->toBe($second->id)
        ->and($first->recommendedAt)->toEqual($diagnostic->evaluatedAt)
        ->and($first->evidenceIds)->toBe($second->evidenceIds);
});

it('requires both human and policy approval for risky scopes and remains proposal-only', function () {
    $build = fn (bool $human, bool $policy): RemediationRecommendation => new RemediationRecommendation(
        id: 'risky-proposal',
        code: 'review_dns_configuration',
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        scope: 'dns',
        rationale: 'Review DNS configuration against provider-versioned evidence.',
        evidenceIds: ['evidence-a'],
        riskLevel: RemediationRecommendation::RISK_HIGH,
        requiresHumanApproval: $human,
        requiresPolicyApproval: $policy,
        executionMode: RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY,
        recommendedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    );

    expect(fn () => $build(false, false))
        ->toThrow(InvalidArgumentException::class, 'require human and policy approval')
        ->and(fn () => $build(true, false))
        ->toThrow(InvalidArgumentException::class, 'require human and policy approval');

    $approvedProposal = $build(true, true);

    expect($approvedProposal->requiresHumanApproval)->toBeTrue()
        ->and($approvedProposal->requiresPolicyApproval)->toBeTrue()
        ->and($approvedProposal->executionMode)->toBe(RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY)
        ->and(method_exists($approvedProposal, 'execute'))->toBeFalse();
});

it('rejects execution-capable remediation output', function () {
    expect(fn () => new RemediationRecommendation(
        id: 'not-a-proposal',
        code: 'review_provider_configuration',
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        scope: 'provider',
        rationale: 'Review provider configuration.',
        evidenceIds: ['evidence-a'],
        riskLevel: RemediationRecommendation::RISK_HIGH,
        requiresHumanApproval: true,
        requiresPolicyApproval: true,
        executionMode: 'execute_now',
        recommendedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'proposal-only');
});

it('rejects recommendations that encode suppression frequency consent or provider-control bypass', function (string $code) {
    expect(fn () => new RemediationRecommendation(
        id: 'unsafe-'.$code,
        code: $code,
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        scope: 'evidence',
        rationale: 'Unsafe recommendation must be rejected.',
        evidenceIds: [],
        riskLevel: RemediationRecommendation::RISK_LOW,
        requiresHumanApproval: false,
        requiresPolicyApproval: false,
        executionMode: RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY,
        recommendedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Unsafe remediation recommendation code is forbidden');
})->with([
    'override_suppression',
    'bypass_frequency',
    'create_consent',
    'create_authorization',
    'rotate_account',
    'deceptive_header',
    'evade_provider',
    'circumvent_limit',
]);

it('rejects malformed audit fields and duplicate evidence references', function () {
    expect(fn () => new RemediationRecommendation(
        id: 'duplicate-evidence',
        code: 'review_dns_configuration',
        workspaceId: 'workspace-a',
        providerKey: 'provider-a',
        messagePurpose: 'marketing',
        scope: 'dns',
        rationale: 'Review DNS configuration.',
        evidenceIds: ['evidence-a', 'evidence-a'],
        riskLevel: RemediationRecommendation::RISK_HIGH,
        requiresHumanApproval: true,
        requiresPolicyApproval: true,
        executionMode: RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY,
        recommendedAt: new DateTimeImmutable('2026-09-18T11:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'evidenceIds must be unique');
});
