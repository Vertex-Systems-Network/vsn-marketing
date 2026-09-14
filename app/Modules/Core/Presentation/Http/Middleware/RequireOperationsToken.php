<?php

namespace App\Modules\Core\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireOperationsToken
{
    private const MINIMUM_TOKEN_LENGTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('operations.token');
        $provided = $request->header('X-Operations-Token');

        if (
            ! is_string($expected)
            || strlen($expected) < self::MINIMUM_TOKEN_LENGTH
            || ! is_string($provided)
            || ! hash_equals($expected, $provided)
        ) {
            abort(404);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
