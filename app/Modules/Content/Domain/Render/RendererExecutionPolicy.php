<?php

namespace App\Modules\Content\Domain\Render;

use InvalidArgumentException;

final readonly class RendererExecutionPolicy
{
    public function __construct(
        public int $maxDurationMs = 5000,
        public int $maxMemoryMb = 256,
        public int $maxOutputBytes = 5242880,
        public int $maxInputNodes = 5000,
    ) {
        if ($this->maxDurationMs < 100 || $this->maxDurationMs > 30000) {
            throw new InvalidArgumentException('Renderer duration limit must be between 100 and 30000 milliseconds.');
        }

        if ($this->maxMemoryMb < 16 || $this->maxMemoryMb > 1024) {
            throw new InvalidArgumentException('Renderer memory limit must be between 16 and 1024 MiB.');
        }

        if ($this->maxOutputBytes < 1 || $this->maxOutputBytes > 10485760) {
            throw new InvalidArgumentException('Renderer output limit must be between 1 and 10485760 bytes.');
        }

        if ($this->maxInputNodes < 1 || $this->maxInputNodes > 10000) {
            throw new InvalidArgumentException('Renderer input node limit must be between 1 and 10000 nodes.');
        }
    }

    /**
     * @return array{
     *     max_duration_ms: int,
     *     max_memory_mb: int,
     *     max_output_bytes: int,
     *     max_input_nodes: int,
     *     network_access: false,
     *     filesystem_access: false,
     *     shell_access: false,
     *     ambient_secret_access: false
     * }
     */
    public function toArray(): array
    {
        return [
            'max_duration_ms' => $this->maxDurationMs,
            'max_memory_mb' => $this->maxMemoryMb,
            'max_output_bytes' => $this->maxOutputBytes,
            'max_input_nodes' => $this->maxInputNodes,
            'network_access' => false,
            'filesystem_access' => false,
            'shell_access' => false,
            'ambient_secret_access' => false,
        ];
    }
}
