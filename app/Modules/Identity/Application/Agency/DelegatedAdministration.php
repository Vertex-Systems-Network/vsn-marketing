<?php

namespace App\Modules\Identity\Application\Agency;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Agency\BrandCustomizationPolicy;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agency -> client delegation. A client workspace administrator grants a bounded
 * permission set to one agency workspace; agency members inherit only that set,
 * only for that client, and only while the link is active. Privileged
 * permissions are never delegable, and siblings never see each other.
 */
final readonly class DelegatedAdministration
{
    public const NON_DELEGABLE = [PermissionCatalog::BILLING_MANAGE, PermissionCatalog::IDENTITY_MANAGE,
        PermissionCatalog::PROVIDER_CREDENTIALS_MANAGE, PermissionCatalog::PRIVACY_MANAGE,
        PermissionCatalog::AUDIT_EXPORT, PermissionCatalog::AGENCY_MANAGE];

    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private AuditRecorder $audit,
        private BrandCustomizationPolicy $brands,
    ) {}

    /** @param list<string> $permissions */
    public function link(User $clientAdmin, string $clientWorkspaceId, string $agencyWorkspaceId, array $permissions): string
    {
        $this->require($clientAdmin, $clientWorkspaceId, PermissionCatalog::AGENCY_MANAGE);
        $permissions = array_values(array_unique($permissions));
        sort($permissions);
        if ($clientWorkspaceId === $agencyWorkspaceId || $permissions === []
            || array_filter($permissions, fn (string $p): bool => ! PermissionCatalog::contains($p)
                || in_array($p, self::NON_DELEGABLE, true)) !== []
            || ! DB::table('workspaces')->where('id', $agencyWorkspaceId)->exists()) {
            throw new AuthorizationException('Delegation denied.');
        }
        $id = (string) Str::uuid();
        DB::table('agency_client_links')->updateOrInsert(
            ['agency_workspace_id' => $agencyWorkspaceId, 'client_workspace_id' => $clientWorkspaceId],
            ['id' => $id, 'delegated_permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'approved_by' => $clientAdmin->getKey(), 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
        );
        $this->audit->record($clientWorkspaceId, 'agency.delegation.granted',
            ['agency_workspace_id' => $agencyWorkspaceId, 'permissions' => $permissions], actorId: (string) $clientAdmin->getKey());

        return $id;
    }

    public function revoke(User $clientAdmin, string $clientWorkspaceId, string $agencyWorkspaceId): void
    {
        $this->require($clientAdmin, $clientWorkspaceId, PermissionCatalog::AGENCY_MANAGE);
        $updated = DB::table('agency_client_links')->where('client_workspace_id', $clientWorkspaceId)
            ->where('agency_workspace_id', $agencyWorkspaceId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new AuthorizationException('Delegation not found.');
        }
        $this->audit->record($clientWorkspaceId, 'agency.delegation.revoked',
            ['agency_workspace_id' => $agencyWorkspaceId], actorId: (string) $clientAdmin->getKey());
    }

    /** Direct membership permission, or inherited from an agency the user administers. */
    public function allows(User $user, string $workspaceId, string $permission): bool
    {
        if ($this->direct($user, $workspaceId, $permission)) {
            return true;
        }
        if (in_array($permission, self::NON_DELEGABLE, true)) {
            return false;
        }
        foreach (DB::table('agency_client_links')->where('client_workspace_id', $workspaceId)
            ->whereNull('revoked_at')->get(['agency_workspace_id', 'delegated_permissions']) as $link) {
            $delegated = json_decode((string) $link->delegated_permissions, true);
            if (is_array($delegated) && in_array($permission, $delegated, true)
                && $this->direct($user, (string) $link->agency_workspace_id, PermissionCatalog::AGENCY_MANAGE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function customizeBrand(User $actor, string $workspaceId, string $brandId, array $input): array
    {
        if (! $this->allows($actor, $workspaceId, PermissionCatalog::TEMPLATE_PUBLISH)) {
            throw new AuthorizationException('Brand customization denied.');
        }
        if (! DB::table('brands')->where('id', $brandId)->where('workspace_id', $workspaceId)->exists()) {
            throw new AuthorizationException('Brand not found in workspace.');
        }
        $clean = $this->brands->sanitize($input);
        if ($clean === null) {
            throw new AuthorizationException('Brand customization rejected.');
        }
        DB::table('brand_customizations')->updateOrInsert(['brand_id' => $brandId],
            [...$clean, 'workspace_id' => $workspaceId, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($workspaceId, 'agency.brand.customized', ['brand_id' => $brandId],
            actorId: (string) $actor->getKey(), subjectType: 'brand', subjectId: $brandId);

        return $this->presentation($workspaceId, $brandId);
    }

    /**
     * Customization plus system-owned disclosures that customization cannot alter.
     *
     * @return array<string, mixed>
     */
    public function presentation(string $workspaceId, string $brandId): array
    {
        $custom = DB::table('brand_customizations')->where('brand_id', $brandId)->where('workspace_id', $workspaceId)->first();
        $owner = DB::table('workspaces')->where('id', $workspaceId)->value('name');

        return [
            'display_name' => $custom->display_name ?? null,
            'primary_color' => $custom->primary_color ?? null,
            'logo_url' => $custom->logo_url ?? null,
            'security_origin' => (string) config('app.url'),
            'disclosures' => [
                'ownership' => 'Data controller: '.$owner,
                'consent' => 'You receive messages only with recorded consent and can unsubscribe at any time.',
                'billing' => 'Billing is managed by VSN for '.$owner.'.',
            ],
        ];
    }

    private function require(User $user, string $workspaceId, string $permission): void
    {
        if (! $this->direct($user, $workspaceId, $permission)) {
            throw new AuthorizationException('Delegation management denied.');
        }
    }

    private function direct(User $user, string $workspaceId, string $permission): bool
    {
        $organization = DB::table('workspaces')->where('id', $workspaceId)->value('organization_id');

        return is_string($organization) && $this->authorizer->allows($user,
            new TenantContext($organization, $workspaceId, null, (string) $user->getKey()), $permission);
    }
}
