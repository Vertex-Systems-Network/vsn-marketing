<?php

namespace App\Modules\Analytics\Domain;

use InvalidArgumentException;

/** Structured R0 claims only; the display renderer owns all prose and numbers. */
final class AnalyticsExplanation
{
    public function metrics(array $report): array
    {
        $metrics = [];
        if (isset($report['value']) && is_int($report['value'])) {
            $metrics['count'] = $report['value'];
        }
        foreach (['entered_subjects', 'cohort_size', 'current_active', 'previous_active'] as $name) {
            if (isset($report['result'][$name]) && is_int($report['result'][$name])) {
                $metrics[$name] = $report['result'][$name];
            }
        }
        foreach ($report['result']['steps'] ?? [] as $i => $step) {
            $metrics['funnel.step.'.($i + 1)] = $step['subjects'];
            $metrics['funnel.step.'.($i + 1).'.denominator'] = $step['denominator'];
        }
        foreach ($report['result']['bins'] ?? [] as $i => $bin) {
            $metrics['retention.bin.'.($i + 1).'.censored'] = $bin['censored_subjects'];
            $metrics['retention.bin.'.($i + 1).'.eligible'] = $bin['eligible_subjects'];
            if ($bin['retained_subjects'] !== null) {
                $metrics['retention.bin.'.($i + 1).'.returned'] = $bin['retained_subjects'];
            }
        }
        foreach ($report['result']['categories'] ?? [] as $category => $count) {
            $metrics['lifecycle.'.$category] = $count;
        }
        foreach ($report['result']['groups'] ?? [] as $group) {
            foreach (['events', 'unique_subjects'] as $name) {
                $metrics['performance.'.$group['dimension'].'.'.$group['event_type'].'.'.$name] = $group[$name];
            }
        }
        foreach ($report['result']['observed_ltv'] ?? [] as $currency => $value) {
            $metrics[$currency.'.observed_ltv.net_minor_units'] = $value['net_minor_units'];
            $metrics[$currency.'.observed_ltv.eligible_subjects'] = $value['eligible_subjects'];
        }
        foreach ($report['result']['currencies'] ?? [] as $currency => $values) {
            foreach (['gross', 'refunded', 'net'] as $name) {
                $metrics[$currency.'.'.$name] = $values[$name];
            }
        }

        return $metrics;
    }

    public function validate(array $output, array $report): array
    {
        if (array_diff(array_keys($output), ['snapshot_id', 'fingerprint', 'facts', 'inferences']) !== []
            || ($output['snapshot_id'] ?? null) !== $report['id'] || ($output['fingerprint'] ?? null) !== $report['fingerprint']
            || ! is_array($output['facts'] ?? null) || ! array_is_list($output['facts']) || count($output['facts']) > 10
            || ! is_array($output['inferences'] ?? null) || ! array_is_list($output['inferences']) || count($output['inferences']) > 3) {
            throw new InvalidArgumentException('Explanation snapshot/schema denied.');
        }
        $metrics = $this->metrics($report);
        $seen = [];
        foreach ($output['facts'] as $fact) {
            if (! is_array($fact) || count($fact) !== 2 || ! is_string($fact['metric'] ?? null)
                || ! array_key_exists($fact['metric'], $metrics) || isset($seen[$fact['metric']])
                || ($fact['value'] ?? null) !== $metrics[$fact['metric']]) {
                throw new InvalidArgumentException('Explanation contains unsupported or incorrect measured fact.');
            }
            $seen[$fact['metric']] = true;
        }
        foreach ($output['inferences'] as $inference) {
            if (! in_array($inference, ['source_coverage_unknown', 'observed_change_needs_investigation', 'horizon_incomplete'], true)) {
                throw new InvalidArgumentException('Explanation inference/command denied.');
            }
        }

        return ['snapshot_id' => $report['id'], 'fingerprint' => $report['fingerprint'], 'facts' => $output['facts'],
            'inferences' => $output['inferences'], 'risk_tier' => 'R0', 'causal' => false];
    }
}
