<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use InvalidArgumentException;

final readonly class ConnectorPlanCandidate
{
    /**
     * @param list<ConnectorCapabilityCandidate> $capabilities
     * @param array<string, array<string, mixed>> $authSchemes
     * @param list<string> $servers
     * @param list<string> $unknownSemantics
     */
    public function __construct(
        public string $workspaceId,
        public string $providerKey,
        public ConnectorSourceProvenance $provenance,
        public array $capabilities,
        public array $authSchemes,
        public array $servers,
        public array $unknownSemantics,
        public bool $executable = false,
        public bool $authorityGranted = false,
        public string $activationState = 'candidate_only',
    ) {
        if (trim($providerKey) === '') {
            throw new InvalidArgumentException('Connector plan candidate requires a provider key.');
        }

        if ($workspaceId !== $provenance->workspaceId) {
            throw new InvalidArgumentException('Connector plan candidate cannot cross workspace provenance.');
        }

        if ($executable || $authorityGranted || $activationState !== 'candidate_only') {
            throw new InvalidArgumentException('TASK-0083 connector plans must remain non-executable and authority-free.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'provenance' => $this->provenance->toArray(),
            'capabilities' => array_map(
                static fn (ConnectorCapabilityCandidate $capability): array => $capability->toArray(),
                $this->capabilities,
            ),
            'auth_schemes' => $this->authSchemes,
            'servers' => $this->servers,
            'unknown_semantics' => $this->unknownSemantics,
            'executable' => $this->executable,
            'authority_granted' => $this->authorityGranted,
            'activation_state' => $this->activationState,
        ];
    }
}
