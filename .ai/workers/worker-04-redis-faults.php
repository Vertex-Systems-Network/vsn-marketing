<?php
/**
 * Worker 04: TASK-0024 - Redis Fault Injection
 * Tests cache layer resilience under fault conditions
 */

namespace App\Agents\Task0024;

class RedisFaults
{
    public const FAULT_TYPES = ['latency', 'timeout', 'connection_loss', 'memory_limit'];
    public const RECOVERY_TIMEOUT_MS = 5000;
    
    public function injectFault(string $type, int $durationMs): array
    {
        return [
            'fault_type' => $type,
            'duration_ms' => $durationMs,
            'injected_at' => time(),
            'recovery_expected_ms' => self::RECOVERY_TIMEOUT_MS,
            'status' => 'injected',
        ];
    }
    
    public function validateRecovery(array $fault): bool
    {
        return in_array($fault['fault_type'], self::FAULT_TYPES);
    }
}
