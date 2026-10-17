<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Modules\Identity\Application\Sessions\WorkspaceSessionPolicy;
use App\Modules\Identity\Application\Team\TeamAdministration;
use App\Modules\Identity\Application\Team\WorkspaceProvisioner;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

final class TeamController
{
    public function __construct(
        private readonly TeamAdministration $team,
        private readonly TenantContextStore $tenant,
    ) {}

    /** Self-serve signup (FR-IAM-01): returns a bearer token for the new founder. */
    public function register(Request $request, WorkspaceProvisioner $provisioner, WorkspaceSessionPolicy $sessions): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:1024', Password::min(12)],
            'workspace_name' => ['required', 'string', 'max:120'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $result = $provisioner->signUp($data['name'], $data['email'], $data['password'], $data['workspace_name']);
        $generation = $sessions->issue((string) $result['user']->getKey());
        $minutes = (int) config('sanctum.expiration');
        $token = $result['user']->createToken($data['device_name'] ?? 'web', ['*'], $minutes > 0 ? now()->addMinutes($minutes) : null);
        $token->accessToken->forceFill(['session_generation' => $generation])->save();

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'workspace_id' => $result['workspace_id'],
        ], 201);
    }

    public function members(): JsonResponse
    {
        $workspaceId = $this->tenant->require()->workspaceId;
        $rows = DB::table('workspace_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspaceId)->orderBy('u.name')->orderBy('m.id')->limit(500)
            ->get(['m.id', 'u.name', 'u.email']);
        $roles = DB::table('workspace_membership_roles as a')->join('workspace_roles as r', 'r.id', '=', 'a.workspace_role_id')
            ->whereIn('a.workspace_membership_id', $rows->pluck('id')->all())->where('r.workspace_id', $workspaceId)
            ->get(['a.workspace_membership_id', 'r.key'])->groupBy('workspace_membership_id');

        return new JsonResponse(['data' => $rows->map(fn (object $m): array => [
            'id' => (string) $m->id,
            'name' => (string) $m->name,
            'email' => (string) $m->email,
            'roles' => $roles->get($m->id, collect())->pluck('key')->sort()->values()->all(),
        ])->values()->all()]);
    }

    public function invites(): JsonResponse
    {
        $rows = DB::table('workspace_invites as i')->join('workspace_roles as r', 'r.id', '=', 'i.workspace_role_id')
            ->where('i.workspace_id', $this->tenant->require()->workspaceId)
            ->whereNull('i.accepted_at')->whereNull('i.revoked_at')->where('i.expires_at', '>', now())
            ->orderByDesc('i.created_at')->limit(200)->get(['i.id', 'i.email', 'r.key as role', 'i.expires_at']);

        return new JsonResponse(['data' => $rows->map(fn (object $i): array => [
            'id' => (string) $i->id,
            'email' => (string) $i->email,
            'role' => (string) $i->role,
            'expires_at' => Carbon::parse($i->expires_at)->toIso8601ZuluString(),
        ])->values()->all()]);
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_id' => ['required', 'uuid'],
        ]);

        return new JsonResponse(['data' => $this->team->invite($this->actor($request), $this->tenant->require(), $data['email'], $data['role_id'])], 201);
    }

    public function revokeInvite(Request $request, string $invite): Response
    {
        $this->team->revoke($this->actor($request), $this->tenant->require(), $invite);

        return response()->noContent();
    }

    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        return new JsonResponse(['data' => ['workspace_id' => $this->team->accept($this->actor($request), $token)]]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['required', 'array', 'min:1', 'max:64'],
            'permissions.*' => ['string', 'max:64'],
        ]);
        $id = $this->team->createRole($this->actor($request), $this->tenant->require(), $data['key'], $data['name'], $data['permissions']);

        return new JsonResponse(['data' => ['id' => $id]], 201);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        return $user;
    }
}
