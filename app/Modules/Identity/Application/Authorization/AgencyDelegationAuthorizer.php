<?php

namespace App\Modules\Identity\Application\Authorization;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Explicit actor-and-client grants; no automatic organization-wide inheritance. */
final readonly class AgencyDelegationAuthorizer
{
    public function __construct(private WorkspaceAuthorizer $roles) {}

    public function allows(User $user, string $agencyWorkspaceId, TenantContext $client, string $permission): bool
    {
        if ($agencyWorkspaceId === '' || $client->workspaceId === '' || $agencyWorkspaceId === $client->workspaceId
            || $client->actorId !== (string) $user->getKey() || ! PermissionCatalog::contains($permission)) {
            return false;
        }

        $agency = DB::table('workspaces')->where('id', $agencyWorkspaceId)->first(['organization_id']);
        if ($agency === null || ! $this->roles->allows($user, new TenantContext(
            (string) $agency->organization_id, $agencyWorkspaceId, null, (string) $user->getKey(),
        ), $permission)) {
            return false;
        }

        return DB::table('agency_client_delegations')
            ->join('workspaces', 'workspaces.id', '=', 'agency_client_delegations.client_workspace_id')
            ->where('agency_client_delegations.agency_workspace_id', $agencyWorkspaceId)
            ->where('agency_client_delegations.client_workspace_id', $client->workspaceId)
            ->where('agency_client_delegations.user_id', $user->getKey())
            ->where('agency_client_delegations.permission', $permission)
            ->where('workspaces.organization_id', $client->organizationId)
            ->exists();
    }
}
