<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Provisioning\DeactivateWorkspaceMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Default-disabled, narrow SCIM PATCH active=false adapter. */
final readonly class ScimDeactivationController
{
    public function __construct(private DeactivateWorkspaceMember $deactivation) {}

    public function __invoke(Request $request, string $workspace, string $user): Response
    {
        if (! config('enterprise.scim_deactivation_enabled')) {
            abort(404);
        }

        $secret = $request->bearerToken();
        if (! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 256) {
            throw new AuthorizationException('Provisioning credential denied.');
        }

        $credential = DB::table('workspace_provisioning_credentials')
            ->where('secret_hash', hash('sha256', $secret))
            ->where('workspace_id', $workspace)
            ->where('enabled', true)
            ->where('expires_at', '>', now())
            ->first(['id', 'workspace_id']);
        if ($credential === null) {
            throw new AuthorizationException('Provisioning credential denied.');
        }

        $body = $request->json()->all();
        $operation = $body['Operations'][0] ?? null;
        if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/scim+json'
            || count($body) !== 2 || ! array_key_exists('schemas', $body) || ! array_key_exists('Operations', $body)
            || $body['schemas'] !== ['urn:ietf:params:scim:api:messages:2.0:PatchOp']
            || ! is_array($body['Operations']) || count($body['Operations']) !== 1
            || ! is_array($operation) || count($operation) !== 3
            || ($operation['op'] ?? null) !== 'replace' || ($operation['path'] ?? null) !== 'active'
            || ! array_key_exists('value', $operation) || $operation['value'] !== false) {
            return response()->json([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'detail' => 'Unsupported provisioning operation.', 'status' => '400',
            ], 400, ['Content-Type' => 'application/scim+json']);
        }

        $this->deactivation->execute(
            (string) $credential->workspace_id,
            $workspace,
            $user,
            true,
            true,
            ['user.deactivate'],
            (string) $credential->id,
        );

        return response()->noContent();
    }
}
