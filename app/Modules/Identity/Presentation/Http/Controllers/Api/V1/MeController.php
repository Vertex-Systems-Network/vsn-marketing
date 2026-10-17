<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MeController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        return new JsonResponse(['data' => [
            'id' => (string) $user->getKey(),
            'name' => (string) $user->getAttribute('name'),
            'email' => (string) $user->getAttribute('email'),
            'email_verified' => $user->getAttribute('email_verified_at') !== null,
        ]]);
    }

    /** Workspaces the caller belongs to, with the permissions granted in each. */
    public function workspaces(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        $rows = DB::table('workspace_memberships as memberships')
            ->join('workspaces', 'workspaces.id', '=', 'memberships.workspace_id')
            ->where('memberships.user_id', $user->getKey())
            ->orderBy('workspaces.name')->orderBy('workspaces.id')
            ->limit(200)
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug', 'memberships.id as membership_id']);

        $grants = DB::table('workspace_membership_roles as assignments')
            ->join('workspace_memberships as memberships', 'memberships.id', '=', 'assignments.workspace_membership_id')
            ->join('workspace_roles as roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.workspace_role_id')
                    ->on('roles.workspace_id', '=', 'memberships.workspace_id');
            })
            ->join('workspace_role_permissions as grants', 'grants.workspace_role_id', '=', 'roles.id')
            ->whereIn('memberships.id', $rows->pluck('membership_id')->all())
            ->get(['memberships.workspace_id', 'grants.permission'])
            ->groupBy('workspace_id');

        return new JsonResponse(['data' => $rows->map(fn ($w): array => [
            'id' => (string) $w->id,
            'name' => (string) $w->name,
            'slug' => (string) $w->slug,
            'permissions' => $grants->get($w->id, collect())->pluck('permission')->unique()->sort()->values()->all(),
        ])->values()->all()]);
    }
}
