<?php

namespace App\Modules\Core\Presentation\Http\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** RFC 9457 problem details for /api/v1 (SRS FR-API-04). */
final class ProblemDetails
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/v1/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $title] = match (true) {
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.'],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Authentication is required.'],
            $e instanceof AuthorizationException => [403, 'forbidden', 'You do not have permission for this action.'],
            $e instanceof ModelNotFoundException => [404, 'not_found', 'The resource was not found.'],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                self::codeFor($e->getStatusCode()),
                self::titleFor($e->getStatusCode()),
            ],
            default => [500, 'server_error', 'Something went wrong.'],
        };

        $body = [
            'type' => 'https://docs.vsn.marketing/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'request_id' => (string) ($request->headers->get('X-Request-Id') ?? $request->attributes->get('request_id', '')),
        ];
        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse($body, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            402 => 'limit_reached',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'csrf_mismatch',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => $status >= 500 ? 'server_error' : 'client_error',
        };
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            404 => 'The resource was not found.',
            405 => 'This method is not allowed.',
            429 => 'Too many requests.',
            503 => 'The service is temporarily unavailable.',
            default => $status >= 500 ? 'Something went wrong.' : 'The request could not be processed.',
        };
    }
}
