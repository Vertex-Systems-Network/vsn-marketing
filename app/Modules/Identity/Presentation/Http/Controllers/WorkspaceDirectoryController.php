<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceDirectoryController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }
        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $number = (int) ($page['page'] ?? 1);
        $rows = DB::table('workspace_memberships as memberships')
            ->join('workspaces', 'workspaces.id', '=', 'memberships.workspace_id')
            ->where('memberships.user_id', $user->getKey())
            ->orderBy('workspaces.name')->orderBy('workspaces.id')
            ->offset(($number - 1) * 25)->limit(26)
            ->get(['workspaces.id', 'workspaces.name', 'memberships.id as membership_id']);

        $visible = $rows->take(25);
        $permissions = DB::table('workspace_membership_roles as assignments')
            ->join('workspace_memberships as memberships', 'memberships.id', '=', 'assignments.workspace_membership_id')
            ->join('workspace_roles as roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.workspace_role_id')
                    ->on('roles.workspace_id', '=', 'memberships.workspace_id');
            })
            ->join('workspace_role_permissions as grants', 'grants.workspace_role_id', '=', 'roles.id')
            ->whereIn('memberships.id', $visible->pluck('membership_id')->all())
            ->get(['memberships.workspace_id', 'grants.permission'])
            ->groupBy('workspace_id');

        $modules = [
            PermissionCatalog::CAMPAIGN_READ => ['name' => 'Publishing', 'route' => 'publishing.operator'],
            PermissionCatalog::JOURNEY_READ => ['name' => 'Journeys', 'route' => 'journeys.operator'],
            PermissionCatalog::CONTACT_READ => ['name' => 'Segments', 'route' => 'segments.operator'],
            PermissionCatalog::ANALYTICS_READ => ['name' => 'Analytics', 'route' => 'analytics.operator'],
            PermissionCatalog::AUDIT_EXPORT => ['name' => 'Enterprise controls', 'route' => 'workspace.enterprise.overview'],
        ];

        return Inertia::render('auth/workspaces', [
            'workspaces' => $visible->map(function ($workspace) use ($permissions, $modules): array {
                $granted = $permissions->get($workspace->id, collect())->pluck('permission')->all();
                $links = [];
                foreach ($modules as $permission => $module) {
                    if (in_array($permission, $granted, true)) {
                        $links[] = ['name' => $module['name'], 'url' => route($module['route'], ['workspace' => $workspace->id])];
                    }
                }

                return ['name' => $workspace->name, 'links' => $links];
            })->values()->all(),
            'previous_url' => $number > 1 ? route('workspaces.directory', ['page' => $number - 1]) : null,
            'next_url' => $rows->count() > 25 ? route('workspaces.directory', ['page' => $number + 1]) : null,
        ]);
    }
}
