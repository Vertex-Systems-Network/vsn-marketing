<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Modules\Identity\Application\Sessions\WorkspaceSessionPolicy;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Mobile bearer tokens (SRS FR-API-02, ADR-0008 D3). */
final class AccessTokenController
{
    public function __construct(private readonly WorkspaceSessionPolicy $sessions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user instanceof User || ! Hash::check($data['password'], (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        $generation = $this->sessions->issue((string) $user->getKey());
        $minutes = (int) config('sanctum.expiration');
        $token = $user->createToken($data['device_name'], ['*'], $minutes > 0 ? now()->addMinutes($minutes) : null);
        $token->accessToken->forceFill(['session_generation' => $generation])->save();

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
