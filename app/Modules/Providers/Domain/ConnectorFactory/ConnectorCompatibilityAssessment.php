<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConnectorCompatibilityAssessment
{
    public string $evidenceSha256;

    /** @param array<string, string> $baselineCapabilities @param array<string, string> $candidateCapabilities */
    private function __construct(
        public string $workspaceId,
        public string $providerKey,
        public string $baselineContractVersion,
        public string $candidateContractVersion,
        public array $baselineCapabilities,
        public array $candidateCapabilities,
        public DateTimeImmutable $assessedAt,
        public string $status,
        public int $score,
        public string $reason,
    ) {
        if (trim($workspaceId) === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException('Compatibility evidence requires workspace and provider identity.');
        }

        if ($assessedAt->getOffset() !== 0 || ! in_array($status, ['compatible', 'incompatible', 'unknown'], true)
            || $score < 0 || $score > 100 || trim($reason) === '') {
            throw new InvalidArgumentException('Compatibility evidence must be UTC-dated and bounded.');
        }

        foreach ([$baselineContractVersion, $candidateContractVersion] as $version) {
            if (self::parseVersion($version) === null) {
                throw new InvalidArgumentException('Contract versions must use explicit major.minor.patch semantic versions.');
            }
        }

        foreach ([$baselineCapabilities, $candidateCapabilities] as $capabilities) {
            foreach ($capabilities as $key => $version) {
                if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', (string) $key) !== 1
                    || is_string($version) === false || self::parseVersion($version) === null) {
                    throw new InvalidArgumentException('Capabilities require stable keys and explicit semantic versions.');
                }
            }
        }

        $this->evidenceSha256 = hash('sha256', json_encode(
            $this->payload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param array<string, string> $baselineCapabilities @param array<string, string> $candidateCapabilities */
    public static function assess(
        string $workspaceId,
        string $providerKey,
        string $baselineContractVersion,
        string $candidateContractVersion,
        array $baselineCapabilities,
        array $candidateCapabilities,
        DateTimeImmutable $assessedAt,
    ): self {
        $baseline = self::parseVersion($baselineContractVersion);
        $candidate = self::parseVersion($candidateContractVersion);

        if ($baseline === null || $candidate === null) {
            throw new InvalidArgumentException('Contract versions must use explicit major.minor.patch semantic versions.');
        }

        if ($baseline[0] !== $candidate[0]) {
            return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'incompatible', 0, 'breaking_contract_major_change');
        }

        if ($baseline[1] !== $candidate[1]) {
            return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'unknown', 0, 'unreviewed_contract_minor_change');
        }

        if (array_diff_key($baselineCapabilities, $candidateCapabilities) !== []) {
            return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'incompatible', 0, 'required_capability_removed');
        }

        if (array_diff_key($candidateCapabilities, $baselineCapabilities) !== []) {
            return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'unknown', 0, 'unreviewed_capability_added');
        }

        $reason = 'exact_contract_match';
        $score = 100;
        foreach ($baselineCapabilities as $key => $beforeVersion) {
            $before = self::parseVersion($beforeVersion);
            $after = self::parseVersion($candidateCapabilities[$key]);
            if ($before === null || $after === null) {
                return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'unknown', 0, 'invalid_capability_version');
            }

            if ($before[0] !== $after[0]) {
                return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'incompatible', 0, 'breaking_capability_major_change');
            }

            if ($before[1] !== $after[1]) {
                return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'unknown', 0, 'unreviewed_capability_minor_change');
            }

            if ($before[2] !== $after[2]) {
                $reason = 'patch_only_compatible_update';
                $score = min($score, 90);
            }
        }

        if ($baseline[2] !== $candidate[2]) {
            $reason = 'patch_only_compatible_update';
            $score = min($score, 90);
        }

        return new self($workspaceId, $providerKey, $baselineContractVersion, $candidateContractVersion, $baselineCapabilities, $candidateCapabilities, $assessedAt, 'compatible', $score, $reason);
    }

    /** @return array{int, int, int}|null */
    private static function parseVersion(string $version): ?array
    {
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'evidence_sha256' => $this->evidenceSha256];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $baseline = $this->baselineCapabilities;
        $candidate = $this->candidateCapabilities;
        ksort($baseline, SORT_STRING);
        ksort($candidate, SORT_STRING);

        return [
            'schema_version' => 1,
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'baseline_contract_version' => $this->baselineContractVersion,
            'candidate_contract_version' => $this->candidateContractVersion,
            'baseline_capabilities' => $baseline,
            'candidate_capabilities' => $candidate,
            'assessed_at' => $this->assessedAt->format(DATE_ATOM),
            'status' => $this->status,
            'score' => $this->score,
            'reason' => $this->reason,
        ];
    }
}
