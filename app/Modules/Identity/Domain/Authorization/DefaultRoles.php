<?php

namespace App\Modules\Identity\Domain\Authorization;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog as P;

/**
 * Default workspace roles provisioned for every new workspace (SRS §2.2, §3.11; TASK-0105 AC-1).
 * Super Admin is a platform role (platform_admins), never a workspace role.
 */
final class DefaultRoles
{
    public const OWNER = 'owner';

    /** Permissions that may only reach a member through the governed two-administrator flow. */
    public const PRIVILEGED = [P::IDENTITY_MANAGE, P::BILLING_MANAGE, P::PRIVACY_MANAGE, P::AGENCY_MANAGE, P::PROVIDER_CREDENTIALS_MANAGE];

    /** @return array<string, array{name: string, permissions: list<string>}> */
    public static function all(): array
    {
        $read = [P::CONTACT_READ, P::CAMPAIGN_READ, P::JOURNEY_READ, P::ANALYTICS_READ];

        return [
            self::OWNER => ['name' => 'Owner', 'permissions' => P::all()],
            'admin' => ['name' => 'Admin', 'permissions' => array_values(array_diff(P::all(), [P::BILLING_MANAGE, P::PRIVACY_MANAGE]))],
            'marketer' => ['name' => 'Marketer', 'permissions' => array_merge($read, [
                P::CONTACT_WRITE, P::CAMPAIGN_CREATE, P::JOURNEY_CREATE, P::TEMPLATE_CREATE, P::AI_EXECUTE,
            ])],
            'approver' => ['name' => 'Approver', 'permissions' => array_merge($read, [
                P::CAMPAIGN_APPROVE, P::CAMPAIGN_SEND, P::JOURNEY_PUBLISH, P::TEMPLATE_PUBLISH, P::AI_APPROVE,
            ])],
            'analyst' => ['name' => 'Analyst', 'permissions' => $read],
            'viewer' => ['name' => 'Viewer', 'permissions' => [P::CAMPAIGN_READ, P::JOURNEY_READ, P::ANALYTICS_READ]],
        ];
    }

    /** @param list<string> $permissions */
    public static function isPrivileged(array $permissions): bool
    {
        return array_intersect($permissions, self::PRIVILEGED) !== [];
    }
}
