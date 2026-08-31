<?php

declare(strict_types=1);

namespace App\Connectors\Quota;

use App\Connectors\Contracts\QuotaSignalInterface;

class QuotaSignalParser implements QuotaSignalInterface
{
    public function ingest(array $signal): array
    {
        // Normalize common keys
        $remaining = null;
        $resetAt = null;
        $windowSeconds = null;
        $scope = null;
        $unit = null;

        if (isset($signal['remaining'])) {
            $remaining = is_numeric($signal['remaining']) ? (int)$signal['remaining'] : null;
        }

        if (!empty($signal['reset']) && is_numeric($signal['reset'])) {
            $resetAt = (int)$signal['reset'];
        }

        if (!empty($signal['reset_at']) && is_numeric($signal['reset_at'])) {
            $resetAt = (int)$signal['reset_at'];
        }

        if (!empty($signal['window_seconds']) && is_numeric($signal['window_seconds'])) {
            $windowSeconds = (int)$signal['window_seconds'];
        }

        if (!empty($signal['scope'])) {
            $scope = (string)$signal['scope'];
        }

        if (!empty($signal['unit'])) {
            $unit = (string)$signal['unit'];
        }

        // Some providers use headers like X-RateLimit-Remaining/Reset
        if (isset($signal['headers']) && is_array($signal['headers'])) {
            $h = array_change_key_case($signal['headers'], CASE_LOWER);
            if (isset($h['x-ratelimit-remaining'])) {
                $remaining = (int)$h['x-ratelimit-remaining'];
            }
            if (isset($h['x-ratelimit-reset'])) {
                $resetAt = is_numeric($h['x-ratelimit-reset']) ? (int)$h['x-ratelimit-reset'] : $resetAt;
            }
            if (isset($h['x-ratelimit-window'])) {
                $windowSeconds = is_numeric($h['x-ratelimit-window']) ? (int)$h['x-ratelimit-window'] : $windowSeconds;
            }
        }

        return [
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'window_seconds' => $windowSeconds,
            'scope' => $scope,
            'unit' => $unit,
            'raw' => $signal,
        ];
    }
}
