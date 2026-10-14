<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Provisioning\ManageProvisioningCredential;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProvisioningCredentialController
{
    public function __construct(private ManageProvisioningCredential $credentials, private TenantContextStore $tenant) {}

    public function issue(Request $request): JsonResponse
    {
        return $this->issued($request);
    }

    public function rotate(Request $request, string $workspace, string $credential): JsonResponse
    {
        return $this->issued($request, $credential);
    }

    public function revoke(Request $request, string $workspace, string $credential): Response
    {
        $this->credentials->revoke($this->tenant->require()->workspaceId, $this->actor($request), $credential);

        return response()->noContent();
    }

    private function issued(Request $request, ?string $replacedId = null): JsonResponse
    {
        $issued = $this->credentials->issue($this->tenant->require()->workspaceId, $this->actor($request), $replacedId);

        return response()->json($issued, 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function actor(Request $request): string
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        return (string) $actor->getKey();
    }
}
