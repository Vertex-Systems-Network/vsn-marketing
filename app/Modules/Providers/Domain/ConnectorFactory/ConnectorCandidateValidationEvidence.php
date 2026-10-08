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
        foreach ($checks as $checkName => $check) {
            $status = $check['status'] ?? null;
            $evidenceSha256 = $check['evidence_sha256'] ?? null;
            $findings = $check['findings'] ?? null;
            $details = $check['details'] ?? null;
            if (in_array($status, ['passed', 'failed'], true) === false
                || is_string($evidenceSha256) === false
                || preg_match('/^[a-f0-9]{64}$/D', $evidenceSha256) !== 1
                || is_array($findings) === false
                || is_array($details) === false
                || ($details['check'] ?? null) !== $checkName
                || ($details['findings'] ?? null) !== $findings
                || $status !== ($findings === [] ? 'passed' : 'failed')
                || hash_equals($evidenceSha256, $this->digest($details)) === false) {
                throw new InvalidArgumentException('Candidate validation evidence contains an invalid or altered review result.');
            }

            foreach ($findings as $finding) {
                if (is_string($finding) === false || trim($finding) === '') {
                    throw new InvalidArgumentException('Candidate validation findings must be non-empty strings.');
                }
            }

            $allChecksPassed = $allChecksPassed && $status === 'passed';
        }

        if ($passed !== $allChecksPassed) {
            throw new InvalidArgumentException('Candidate validation result must match the recorded review checks.');
        }

        $artifactHashes = array_map(
            static fn (array $artifact): array => ['path' => $artifact['path'], 'sha256' => $artifact['sha256']],
            $artifacts,
        );
        foreach ($checks as $check) {
            if (($check['details']['artifact_hashes'] ?? null) !== $artifactHashes) {
                throw new InvalidArgumentException('Candidate review details must bind the complete artifact hash set.');
            }
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

    /** @param array<string, mixed> $details */
    private function digest(array $details): string
    {
        $canonical = $this->canonicalize($details);

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $child): mixed => $this->canonicalize($child), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
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
