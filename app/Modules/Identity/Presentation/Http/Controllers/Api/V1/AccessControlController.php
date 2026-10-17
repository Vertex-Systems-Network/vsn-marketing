<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class AccessControlController
{
    public function __construct(private readonly TenantContextStore $tenant) {}

    /** The permission catalog custom roles are built from (SRS FR-IAM-02). */
    public function permissions(): JsonResponse
    {
        return new JsonResponse(['data' => array_values(PermissionCatalog::all())]);
    }

    public function roles(): JsonResponse
    {
        $workspaceId = $this->tenant->require()->workspaceId;
        $roles = DB::table('workspace_roles')->where('workspace_id', $workspaceId)
            ->orderBy('name')->orderBy('id')->limit(200)->get(['id', 'key', 'name']);
        $grants = DB::table('workspace_role_permissions')
            ->whereIn('workspace_role_id', $roles->pluck('id')->all())
            ->get(['workspace_role_id', 'permission'])->groupBy('workspace_role_id');

        return new JsonResponse(['data' => $roles->map(fn (object $role): array => [
            'id' => (string) $role->id,
            'key' => (string) $role->key,
            'name' => (string) $role->name,
            'permissions' => $grants->get($role->id, collect())->pluck('permission')->unique()->sort()->values()->all(),
        ])->values()->all()]);
    }
}
