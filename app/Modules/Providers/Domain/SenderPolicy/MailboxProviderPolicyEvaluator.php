<?php

namespace App\Modules\Providers\Domain\SenderPolicy;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MailboxProviderPolicyEvaluator
{
    /**
     * @param list<MailboxProviderPolicy> $policies
     */
    public function decide(
        string $workspaceId,
        string $providerKey,
        array $policies,
        DateTimeImmutable $at,
        ?int $observedVolume = null,
        ?bool $configuredHighVolume = null,
    ): MailboxProviderPolicyDecision {
        if (trim($workspaceId) === '' || trim($providerKey) === '') {
            throw new InvalidArgumentException('Workspace and provider keys are required for provider-policy evaluation.');
        }

        if ($observedVolume !== null && $observedVolume < 0) {
            throw new InvalidArgumentException('Observed provider volume must be non-negative when supplied.');
        }

        $effective = [];

        foreach ($policies as $policy) {
            if (! $policy instanceof MailboxProviderPolicy) {
                throw new InvalidArgumentException('Provider-policy evaluator accepts MailboxProviderPolicy values only.');
            }

            if ($policy->workspaceId !== $workspaceId || $policy->providerKey !== $providerKey) {
                continue;
            }

            if ($policy->isEffectiveAt($at)) {
                $effective[] = $policy;
            }
        }

        if ($effective === []) {
            return $this->unknown($workspaceId, $providerKey, $at, 'missing_effective_policy');
        }

        $fresh = array_values(array_filter(
            $effective,
            static fn (MailboxProviderPolicy $policy): bool => $policy->isFreshAt($at),
        ));

        if ($fresh === []) {
            return $this->unknown($workspaceId, $providerKey, $at, 'stale_or_unknown_policy_freshness');
        }

        usort($fresh, static function (MailboxProviderPolicy $left, MailboxProviderPolicy $right): int {
            if ($left->effectiveFrom == $right->effectiveFrom) {
                return strcmp($left->policyVersion, $right->policyVersion);
            }

            return $left->effectiveFrom < $right->effectiveFrom ? -1 : 1;
        });

        $selected = $fresh[array_key_last($fresh)];

        foreach ($fresh as $candidate) {
            if ($candidate === $selected || $candidate->effectiveFrom != $selected->effectiveFrom) {
                continue;
            }

            if ($candidate->policyVersion !== $selected->policyVersion || $candidate->policyKey !== $selected->policyKey) {
                return $this->unknown($workspaceId, $providerKey, $at, 'ambiguous_effective_policy');
            }
        }

        if ($selected->highVolumeThreshold !== null) {
            if ($observedVolume === null) {
                return new MailboxProviderPolicyDecision(
                    workspaceId: $workspaceId,
                    providerKey: $providerKey,
                    policyKey: $selected->policyKey,
                    policyVersion: $selected->policyVersion,
                    classification: ProviderVolumeClassification::Unknown,
                    reasons: ['missing_observed_volume_for_provider_threshold'],
                    requirements: $selected->requirements,
                    provenanceUrl: $selected->provenanceUrl,
                    decidedAt: $at,
                );
            }

            return new MailboxProviderPolicyDecision(
                workspaceId: $workspaceId,
                providerKey: $providerKey,
                policyKey: $selected->policyKey,
                policyVersion: $selected->policyVersion,
                classification: $observedVolume >= $selected->highVolumeThreshold
                    ? ProviderVolumeClassification::HighVolume
                    : ProviderVolumeClassification::BelowThreshold,
                reasons: ['provider_specific_numeric_threshold'],
                requirements: $selected->requirements,
                provenanceUrl: $selected->provenanceUrl,
                decidedAt: $at,
            );
        }

        if ($configuredHighVolume === null) {
            return new MailboxProviderPolicyDecision(
                workspaceId: $workspaceId,
                providerKey: $providerKey,
                policyKey: $selected->policyKey,
                policyVersion: $selected->policyVersion,
                classification: ProviderVolumeClassification::ConfigurationRequired,
                reasons: ['provider_does_not_publish_universal_numeric_threshold'],
                requirements: $selected->requirements,
                provenanceUrl: $selected->provenanceUrl,
                decidedAt: $at,
            );
        }

        return new MailboxProviderPolicyDecision(
            workspaceId: $workspaceId,
            providerKey: $providerKey,
            policyKey: $selected->policyKey,
            policyVersion: $selected->policyVersion,
            classification: $configuredHighVolume
                ? ProviderVolumeClassification::HighVolume
                : ProviderVolumeClassification::BelowThreshold,
            reasons: ['explicit_provider_classification_configuration'],
            requirements: $selected->requirements,
            provenanceUrl: $selected->provenanceUrl,
            decidedAt: $at,
        );
    }

    private function unknown(
        string $workspaceId,
        string $providerKey,
        DateTimeImmutable $at,
        string $reason,
    ): MailboxProviderPolicyDecision {
        return new MailboxProviderPolicyDecision(
            workspaceId: $workspaceId,
            providerKey: $providerKey,
            policyKey: null,
            policyVersion: null,
            classification: ProviderVolumeClassification::Unknown,
            reasons: [$reason],
            requirements: [],
            provenanceUrl: null,
            decidedAt: $at,
        );
    }
}
