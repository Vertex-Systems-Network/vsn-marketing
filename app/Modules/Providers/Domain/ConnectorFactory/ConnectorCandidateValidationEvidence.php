<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use InvalidArgumentException;
use JsonException;

final readonly class ConnectorCandidateValidationEvidence
{
    private const REQUIRED_CHECKS = [
        'static_analysis',
        'dependency_review',
        'contract_review',
        'sandbox_policy',
        'adversarial_review',
    ];

    public string $evidenceSha256;

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @param  array<string, array<string, mixed>>  $checks
     */
    public function __construct(
        public string $candidateId,
        public string $workspaceId,
        public string $providerKey,
        public string $inputPlanSha256,
        public array $artifacts,
        public array $checks,
        public bool $passed,
        public bool $activationAllowed = false,
        public string $activationState = 'candidate_only',
    ) {
        foreach ([$candidateId, $inputPlanSha256] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Candidate validation evidence requires SHA-256 identities.');
            }
        }

        if (trim($workspaceId) === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException('Candidate validation evidence requires a workspace and safe provider key.');
        }

        if ($activationAllowed || $activationState !== 'candidate_only') {
            throw new InvalidArgumentException('Validation evidence cannot grant activation authority.');
        }

        if (array_keys($checks) !== self::REQUIRED_CHECKS) {
            throw new InvalidArgumentException('Candidate validation evidence must contain the complete ordered review set.');
        }

        $allChecksPassed = true;
        foreach ($checks as $check) {
            if (in_array($check['status'] ?? null, ['passed', 'failed'], true) === false
                || preg_match('/^[a-f0-9]{64}$/D', $check['evidence_sha256'] ?? '') !== 1
                || is_array($check['findings'] ?? null) === false) {
                throw new InvalidArgumentException('Candidate validation evidence contains an invalid review result.');
            }

            foreach ($check['findings'] as $finding) {
                if (is_string($finding) === false || trim($finding) === '') {
                    throw new InvalidArgumentException('Candidate validation findings must be non-empty strings.');
                }
            }

            $allChecksPassed = $allChecksPassed && $check['status'] === 'passed';
        }

        if ($passed !== $allChecksPassed) {
            throw new InvalidArgumentException('Candidate validation result must match the recorded review checks.');
        }

        foreach ($artifacts as $artifact) {
            if (isset($artifact['path'], $artifact['sha256']) === false
                || is_string($artifact['path']) === false
                || is_string($artifact['sha256']) === false
                || preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256']) !== 1
                || str_starts_with($artifact['path'], 'connector-candidates/'.$providerKey.'/') === false
                || str_contains($artifact['path'], '..')) {
                throw new InvalidArgumentException('Candidate validation evidence contains an invalid artifact reference.');
            }
        }

        $this->evidenceSha256 = hash('sha256', json_encode(
            $this->payload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'evidence_sha256' => $this->evidenceSha256];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'candidate_id' => $this->candidateId,
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'input_plan_sha256' => $this->inputPlanSha256,
            'artifacts' => $this->artifacts,
            'checks' => $this->checks,
            'passed' => $this->passed,
            'activation_allowed' => false,
            'activation_state' => 'candidate_only',
        ];
    }
}
