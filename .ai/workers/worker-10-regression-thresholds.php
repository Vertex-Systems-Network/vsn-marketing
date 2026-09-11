<?php
/**
 * Worker 10: TASK-0024 - Regression Thresholds
 * Defines automated quality gates for regression detection
 */

namespace App\Agents\Task0024;

class RegressionThresholds
{
    public const THRESHOLDS = [
        'latency_increase_pct' => 10, // max 10% increase
        'error_rate_increase_pct' => 5, // max 5% increase
        'throughput_decrease_pct' => 15, // max 15% decrease
    ];
    
    public function checkRegression(array $baseline, array $current): array
    {
        $regressions = [];
        
        $latencyIncrease = (($current['latency'] - $baseline['latency']) / $baseline['latency']) * 100;
        if ($latencyIncrease > self::THRESHOLDS['latency_increase_pct']) {
            $regressions[] = 'latency_regression';
        }
        
        $errorIncrease = (($current['error_rate'] - $baseline['error_rate']) / $baseline['error_rate']) * 100;
        if ($errorIncrease > self::THRESHOLDS['error_rate_increase_pct']) {
            $regressions[] = 'error_rate_regression';
        }
        
        return [
            'has_regressions' => count($regressions) > 0,
            'regressions' => $regressions,
            'thresholds_used' => self::THRESHOLDS,
            'status' => 'checked',
        ];
    }
}
