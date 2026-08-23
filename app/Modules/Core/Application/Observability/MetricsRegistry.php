<?php

namespace App\Modules\Core\Application\Observability;

use Illuminate\Contracts\Cache\Repository;

final class MetricsRegistry
{
    private const PREFIX = 'vsn:observability:http:';

    public function __construct(private readonly Repository $cache) {}

    public function recordHttpRequest(int $statusCode, int $durationMicroseconds): void
    {
        $this->increment('requests_total');
        $this->increment('duration_microseconds_total', max(0, $durationMicroseconds));

        $class = intdiv($statusCode, 100);
        if ($class >= 1 && $class <= 5) {
            $this->increment("responses_{$class}xx");
        }
    }

    /** @return array{requests_total:int,responses_2xx:int,responses_4xx:int,responses_5xx:int,duration_microseconds_total:int} */
    public function snapshot(): array
    {
        return [
            'requests_total' => $this->value('requests_total'),
            'responses_2xx' => $this->value('responses_2xx'),
            'responses_4xx' => $this->value('responses_4xx'),
            'responses_5xx' => $this->value('responses_5xx'),
            'duration_microseconds_total' => $this->value('duration_microseconds_total'),
        ];
    }

    public function toPrometheus(): string
    {
        $metrics = $this->snapshot();
        $seconds = $metrics['duration_microseconds_total'] / 1_000_000;

        return implode("\n", [
            '# HELP vsn_http_requests_total Total HTTP requests observed by the application.',
            '# TYPE vsn_http_requests_total counter',
            'vsn_http_requests_total '.$metrics['requests_total'],
            '# HELP vsn_http_responses_total HTTP responses by status class.',
            '# TYPE vsn_http_responses_total counter',
            'vsn_http_responses_total{class="2xx"} '.$metrics['responses_2xx'],
            'vsn_http_responses_total{class="4xx"} '.$metrics['responses_4xx'],
            'vsn_http_responses_total{class="5xx"} '.$metrics['responses_5xx'],
            '# HELP vsn_http_request_duration_seconds_sum Cumulative observed HTTP request duration.',
            '# TYPE vsn_http_request_duration_seconds_sum counter',
            'vsn_http_request_duration_seconds_sum '.number_format($seconds, 6, '.', ''),
            '',
        ]);
    }

    private function increment(string $metric, int $amount = 1): void
    {
        $key = self::PREFIX.$metric;
        if (! is_numeric($this->cache->get($key))) {
            $this->cache->forever($key, 0);
        }

        $this->cache->increment($key, $amount);
    }

    private function value(string $metric): int
    {
        $value = $this->cache->get(self::PREFIX.$metric, 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}
