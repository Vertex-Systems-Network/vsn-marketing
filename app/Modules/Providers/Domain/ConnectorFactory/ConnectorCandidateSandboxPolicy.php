<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use InvalidArgumentException;

final readonly class ConnectorCandidateSandboxPolicy
{
    public function __construct(
        public bool $executionPerformed = false,
        public string $networkAccess = 'denied',
        public bool $secretMounts = false,
        public bool $providerAuthority = false,
        public string $inputFilesystem = 'read_only',
        public bool $noNewPrivileges = true,
        public string $seccomp = 'default',
        public int $cpuMillis = 2500,
        public int $memoryMib = 256,
        public int $processLimit = 32,
        public int $timeoutSeconds = 30,
    ) {
        if ($executionPerformed
            || $networkAccess !== 'denied'
            || $secretMounts
            || $providerAuthority
            || $inputFilesystem !== 'read_only'
            || $noNewPrivileges === false
            || $seccomp !== 'default'
            || $cpuMillis < 1 || $cpuMillis > 5000
            || $memoryMib < 1 || $memoryMib > 512
            || $processLimit < 1 || $processLimit > 64
            || $timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new InvalidArgumentException('Candidate sandbox policy must be unprivileged, no-network, bounded and declarative.');
        }
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'name' => 'connector-candidate-sandbox-v1',
            'execution_performed' => $this->executionPerformed,
            'network_access' => $this->networkAccess,
            'secret_mounts' => $this->secretMounts,
            'provider_authority' => $this->providerAuthority,
            'input_filesystem' => $this->inputFilesystem,
            'no_new_privileges' => $this->noNewPrivileges,
            'seccomp' => $this->seccomp,
            'cpu_millis' => $this->cpuMillis,
            'memory_mib' => $this->memoryMib,
            'process_limit' => $this->processLimit,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
