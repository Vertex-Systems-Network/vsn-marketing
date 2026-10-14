<?php

namespace App\Modules\Identity\Domain\Provisioning;

/**
 * A local policy boundary. It never verifies bearer tokens or mutates users.
 * The caller must provide an independently authenticated provisioning principal.
 */
final class ScimProvisioningAdmission
{
    private const ACTIONS = ['user.create', 'user.update', 'user.deactivate', 'group.read'];

    public function allows(
        string $principalWorkspaceId,
        string $requestWorkspaceId,
        string $action,
        array $grantedActions,
        bool $principalAuthenticated,
        bool $provisioningEnabled,
    ): bool {
        if (! $principalAuthenticated || ! $provisioningEnabled) {
            return false;
        }

        if ($principalWorkspaceId === '' || $requestWorkspaceId === '' || ! hash_equals($principalWorkspaceId, $requestWorkspaceId)) {
            return false;
        }

        if (! in_array($action, self::ACTIONS, true) || ! in_array($action, $grantedActions, true)) {
            return false;
        }

        // An IdP may provision membership, never grant local administrative authority.
        return true;
    }
}
