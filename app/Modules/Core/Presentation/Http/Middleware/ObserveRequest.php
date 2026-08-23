<?php

namespace App\Modules\Core\Presentation\Http\Middleware;

use App\Modules\Core\Application\Observability\MetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ObserveRequest
{
    private const CORRELATION_HEADER = 'X-Correlation-ID';

    public function __construct(private MetricsRegistry $metrics)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->correlationId($request);
        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        $startedAt = hrtime(true);

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $exception) {
            $duration = $this->durationMicroseconds($startedAt);
            $this->metrics->recordHttpRequest(500, $duration);
            Log::error('http.request.failed', [
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status' => 500,
                'duration_microseconds' => $duration,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $duration = $this->durationMicroseconds($startedAt);
        $this->metrics->recordHttpRequest($response->getStatusCode(), $duration);
        $response->headers->set(self::CORRELATION_HEADER, $correlationId);

        Log::info('http.request.completed', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'status' => $response->getStatusCode(),
            'duration_microseconds' => $duration,
        ]);

        return $response;
    }

    private function correlationId(Request $request): string
    {
        $candidate = trim((string) $request->headers->get(self::CORRELATION_HEADER, ''));

        if ($candidate !== '' && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $candidate) === 1) {
            return $candidate;
        }

        return Str::uuid()->toString();
    }

    private function durationMicroseconds(int $startedAt): int
    {
        return max(0, intdiv(hrtime(true) - $startedAt, 1_000));
    }
}
