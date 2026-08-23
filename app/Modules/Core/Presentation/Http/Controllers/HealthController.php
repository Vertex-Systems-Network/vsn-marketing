<?php

namespace App\Modules\Core\Presentation\Http\Controllers;

use App\Modules\Core\Application\Observability\HealthStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HealthController
{
    public function live(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'correlation_id' => (string) $request->attributes->get('correlation_id'),
        ]);
    }

    public function ready(Request $request, HealthStatus $health): JsonResponse
    {
        $readiness = $health->readiness();
        $readiness['correlation_id'] = (string) $request->attributes->get('correlation_id');

        return response()->json($readiness, $readiness['status'] === 'ok' ? 200 : 503);
    }
}
