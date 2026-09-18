<?php

namespace App\Modules\DeliveryEngine\Application\Deliverability;

final class BuildRemediationRecommendations
{
    /** @return list<RemediationRecommendation> */
    public function build(DeliverabilityDiagnosticResult $diagnostic): array
    {
        return match ($diagnostic->status) {
            DeliverabilityDiagnosticResult::STATUS_UNKNOWN => [
                $this->recommendation(
                    diagnostic: $diagnostic,
                    code: 'refresh_deliverability_evidence',
                    scope: 'evidence',
                    rationale: 'Provider-versioned deliverability evidence is unavailable. Refresh or obtain trusted evidence before making a remediation decision.',
                    riskLevel: RemediationRecommendation::RISK_LOW,
                ),
            ],
            DeliverabilityDiagnosticResult::STATUS_REVIEW => [
                $this->recommendation(
                    diagnostic: $diagnostic,
                    code: 'investigate_deliverability_evidence',
                    scope: 'evidence',
                    rationale: 'Deliverability evidence requires review before remediation: '.implode(', ', $diagnostic->reasons).'.',
                    riskLevel: RemediationRecommendation::RISK_MEDIUM,
                ),
            ],
            DeliverabilityDiagnosticResult::STATUS_OBSERVED => [
                $this->recommendation(
                    diagnostic: $diagnostic,
                    code: 'continue_provider_observation',
                    scope: 'monitoring',
                    rationale: 'Current evidence is observable but does not establish provider health or permission to send. Continue provider-versioned monitoring.',
                    riskLevel: RemediationRecommendation::RISK_LOW,
                ),
            ],
            default => throw new InvalidArgumentException('Unsupported deliverability diagnostic status.'),
        };
    }

    private function recommendation(
        DeliverabilityDiagnosticResult $diagnostic,
        string $code,
        string $scope,
        string $rationale,
        string $riskLevel,
    ): RemediationRecommendation {
        $evidenceIds = $diagnostic->evidenceIds;
        sort($evidenceIds);

        $id = 'remediation-'.hash('sha256', implode("\0", [
            $diagnostic->workspaceId,
            $diagnostic->providerKey,
            $diagnostic->messagePurpose,
            $diagnostic->status,
            $code,
            $diagnostic->evaluatedAt->format(DATE_ATOM),
            implode(',', $evidenceIds),
        ]));

        return new RemediationRecommendation(
            id: $id,
            code: $code,
            workspaceId: $diagnostic->workspaceId,
            providerKey: $diagnostic->providerKey,
            messagePurpose: $diagnostic->messagePurpose,
            scope: $scope,
            rationale: $rationale,
            evidenceIds: $evidenceIds,
            riskLevel: $riskLevel,
            requiresHumanApproval: false,
            requiresPolicyApproval: false,
            executionMode: RemediationRecommendation::EXECUTION_MODE_PROPOSAL_ONLY,
            recommendedAt: $diagnostic->evaluatedAt,
        );
    }
}
