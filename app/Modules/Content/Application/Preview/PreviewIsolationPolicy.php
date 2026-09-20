<?php

namespace App\Modules\Content\Application\Preview;

use InvalidArgumentException;

final readonly class PreviewIsolationPolicy
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public int $maxDurationMs = 2000,
        public int $maxMemoryMb = 128,
        public int $maxOutputBytes = 1048576,
        public int $maxFindings = 100,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->maxDurationMs < 100 || $this->maxDurationMs > 10000) {
            throw new InvalidArgumentException('Preview duration limit must be between 100 and 10000 milliseconds.');
        }

        if ($this->maxMemoryMb < 16 || $this->maxMemoryMb > 512) {
            throw new InvalidArgumentException('Preview memory limit must be between 16 and 512 MiB.');
        }

        if ($this->maxOutputBytes < 1 || $this->maxOutputBytes > 5242880) {
            throw new InvalidArgumentException('Preview output limit must be between 1 and 5242880 bytes.');
        }

        if ($this->maxFindings < 1 || $this->maxFindings > 500) {
            throw new InvalidArgumentException('Preview finding limit must be between 1 and 500.');
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported preview isolation policy schema version: {$this->schemaVersion}");
        }
    }

    /**
     * @return array{
     *     schema_version: int,
     *     max_duration_ms: int,
     *     max_memory_mb: int,
     *     max_output_bytes: int,
     *     max_findings: int,
     *     network_access: false,
     *     filesystem_access: false,
     *     shell_access: false,
     *     ambient_secret_access: false,
     *     canonical_state_mutation: false,
     *     provider_publishing: false
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'max_duration_ms' => $this->maxDurationMs,
            'max_memory_mb' => $this->maxMemoryMb,
            'max_output_bytes' => $this->maxOutputBytes,
            'max_findings' => $this->maxFindings,
            'network_access' => false,
            'filesystem_access' => false,
            'shell_access' => false,
            'ambient_secret_access' => false,
            'canonical_state_mutation' => false,
            'provider_publishing' => false,
        ];
    }
}
