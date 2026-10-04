<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\AnalyticsExplanation;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AnalyticsInsights
{
    public function __construct(private AnalyticsFacts $facts, private AnalyticsExplanation $explanations, private Clock $clock) {}

    public function validateExplanation(TenantContext $actor, string $snapshot, array $output): array
    {
        $report = $this->facts->readSnapshot($actor, $snapshot);
        if (new DateTimeImmutable($report['receipt_cutoff_utc']) < $this->clock->now()->modify('-1 day')) {
            throw new InvalidArgumentException('Explanation snapshot is stale.');
        }

        return $this->explanations->validate($output, $report);
    }

    public function anomaly(TenantContext $actor, string $snapshot, array $baselineIds): array
    {
        $current = $this->facts->readSnapshot($actor, $snapshot);
        if (count($baselineIds) < 7 || count($baselineIds) > 14 || count(array_unique($baselineIds)) !== count($baselineIds)
            || ! is_int($current['value'] ?? null) || ($current['excluded'] ?? 0) !== 0) {
            return ['status' => 'insufficient_evidence', 'method' => 'local_daily_sample_zscore_v1'];
        }
        $values = $windows = [];
        $currentStart = new DateTimeImmutable($current['start_utc']);
        $currentEnd = new DateTimeImmutable($current['end_utc']);
        if ($currentEnd->getTimestamp() - $currentStart->getTimestamp() !== 86400
            || new DateTimeImmutable($current['receipt_cutoff_utc']) > $currentEnd->modify('+1 day')
            || new DateTimeImmutable($current['receipt_cutoff_utc']) < $this->clock->now()->modify('-1 day')) {
            return ['status' => 'stale_or_non_daily', 'method' => 'local_daily_sample_zscore_v1'];
        }
        foreach ($baselineIds as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException('Invalid baseline reference.');
            }
            $r = $this->facts->readSnapshot($actor, $id);
            $start = new DateTimeImmutable($r['start_utc']);
            $end = new DateTimeImmutable($r['end_utc']);
            if ($r['definition_hash'] !== $current['definition_hash'] || ! is_int($r['value'] ?? null)
                || $r['excluded'] !== 0 || $end > $currentStart || $start < $currentStart->modify('-14 days')
                || $end->getTimestamp() - $start->getTimestamp() !== 86400
                || isset($windows[$r['start_utc']]) || new DateTimeImmutable($r['receipt_cutoff_utc']) > $end->modify('+1 day')) {
                return ['status' => 'incomparable_baseline', 'method' => 'local_daily_sample_zscore_v1'];
            }
            $windows[$r['start_utc']] = true;
            $values[] = $r['value'];
        }
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn (int $v): float => ($v - $mean) ** 2, $values)) / (count($values) - 1);
        if ($variance <= 0) {
            return ['status' => 'zero_variance', 'method' => 'local_daily_sample_zscore_v1'];
        }
        $z = ($current['value'] - $mean) / sqrt($variance);

        return ['status' => abs($z) >= 3 ? 'local_signal' : 'within_local_baseline', 'method' => 'local_daily_sample_zscore_v1',
            'snapshot_id' => $snapshot, 'baseline_ids' => $baselineIds, 'baseline_n' => count($values),
            'baseline_mean' => $mean, 'sample_sd' => sqrt($variance), 'z_score' => $z, 'threshold' => 3,
            'source_completeness' => 'unknown', 'causal' => false, 'calibrated_probability' => false];
    }
}
