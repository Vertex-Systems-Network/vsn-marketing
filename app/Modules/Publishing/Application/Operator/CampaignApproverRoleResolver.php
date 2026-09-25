<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class CampaignApproverRoleResolver
{
    public function __construct(private DatabaseManager $database) {}

    public function resolve(User $user, TenantContext $context): string
    {
        if ((string) $user->getKey() !== $context->actorId) {
            throw new AuthorizationException('Campaign approval actor does not match tenant context.');
        }

        $role = $this->database->connection()->table('workspace_memberships')
            ->join(
                'workspace_membership_roles',
                'workspace_membership_roles.workspace_membership_id',
                '=',
                'workspace_memberships.id',
            )
            ->join('workspace_roles', function ($join): void {
                $join->on('workspace_roles.id', '=', 'workspace_membership_roles.workspace_role_id')
                    ->on('workspace_roles.workspace_id', '=', 'workspace_memberships.workspace_id');
            })
            ->join(
                'workspace_role_permissions',
                'workspace_role_permissions.workspace_role_id',
                '=',
                'workspace_roles.id',
            )
            ->where('workspace_memberships.workspace_id', $context->workspaceId)
            ->where('workspace_memberships.user_id', $user->getKey())
            ->where('workspace_roles.workspace_id', $context->workspaceId)
            ->where('workspace_role_permissions.permission', PermissionCatalog::CAMPAIGN_APPROVE)
            ->orderBy('workspace_roles.key')
            ->value('workspace_roles.key');

        if (! is_string($role) || trim($role) === '') {
            throw new AuthorizationException('Campaign approval requires a current workspace approver role.');
        }

        return $role;
    }
}
