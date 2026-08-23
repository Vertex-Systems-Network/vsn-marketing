<?php

namespace App\Modules\Core\Presentation\Http\Controllers;

use App\Modules\Core\Application\Runtime\RuntimeSnapshot;
use Illuminate\Http\JsonResponse;

final class RuntimeStatusController
{
    public function __invoke(RuntimeSnapshot $runtime): JsonResponse
    {
        return response()->json(['data' => $runtime->toArray()]);
    }
}
