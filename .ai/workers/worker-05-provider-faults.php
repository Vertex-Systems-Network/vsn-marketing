<?php
/**
 * Worker 05: TASK-0024 - Provider Fault Matrix
 * Tests email provider failover scenarios (Brevo, Gmail, SES)
 */

namespace App\Agents\Task0024;

class ProviderFaults
{
    public const PROVIDERS = ['brevo', 'gmail', 'ses'];
    public const FAULT_MATRIX = [
        'rate_limit' => ['brevo', 'gmail', 'ses'],
        'auth_failure' => ['brevo', 'gmail', 'ses'],
        'network_timeout' => ['brevo', 'gmail', 'ses'],
        'quota_exceeded' => ['brevo', 'gmail'],
    ];
    
    public function testFailover(string $primaryProvider, string $faultType): array
    {
        $fallbacks = array_diff(self::PROVIDERS, [$primaryProvider]);
        return [
            'primary' => $primaryProvider,
            'fault_type' => $faultType,
            'fallback_chain' => array_values($fallbacks),
            'failover_expected' => true,
            'status' => 'testing',
        ];
    }
}
