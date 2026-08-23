<?php

namespace App\Modules\Core\Presentation\Http\Controllers;

use App\Modules\Core\Application\Observability\MetricsRegistry;
use Illuminate\Http\Response;

final class MetricsController
{
    public function __invoke(MetricsRegistry $metrics): Response
    {
        return response($metrics->toPrometheus(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
