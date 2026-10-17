<?php

namespace App\Modules\Identity\Application\Team;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\DefaultRoles;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Self-serve signup: user, organization, workspace, default roles and the founding Owner (FR-IAM-01/02). */
final readonly class WorkspaceProvisioner
{
    public function __construct(private WorkspaceRoleManager $roles, private AuditRecorder $audit) {}

    /** @return array{user: User, workspace_id: string} */
    public function signUp(string $name, string $email, string $password, string $workspaceName): array
    {
        return DB::transaction(function () use ($name, $email, $password, $workspaceName): array {
            $user = User::query()->create(['name' => $name, 'email' => Str::lower($email), 'password' => $password]);
            $slug = Str::slug($workspaceName) ?: 'workspace';
            $organization = Organization::query()->create(['name' => $workspaceName, 'slug' => $slug.'-'.Str::lower(Str::random(8))]);
            $workspace = Workspace::query()->create([
                'organization_id' => $organization->getKey(), 'name' => $workspaceName, 'slug' => $slug,
            ]);
            $workspaceId = (string) $workspace->getKey();

            $roleIds = $this->provisionDefaultRoles($workspaceId);
            $membership = $this->roles->addMember($user, $workspaceId);
            // Bootstrap exception: the founder of a new workspace is its first Owner.
            $this->roles->assignRole($membership, $roleIds[DefaultRoles::OWNER]);

            $this->audit->record($workspaceId, 'identity.workspace.created', [
                'default_roles' => array_keys($roleIds),
            ], actorId: (string) $user->getKey(), subjectType: 'workspace', subjectId: $workspaceId);

            return ['user' => $user, 'workspace_id' => $workspaceId];
        });
    }

    /** @return array<string, string> role key => role id */
    public function provisionDefaultRoles(string $workspaceId): array
    {
        $ids = [];
        foreach (DefaultRoles::all() as $key => $definition) {
            $existing = DB::table('workspace_roles')->where('workspace_id', $workspaceId)->where('key', $key)->value('id');
            $id = is_string($existing) ? $existing : $this->roles->createRole($workspaceId, $key, $definition['name']);
            foreach ($definition['permissions'] as $permission) {
                $this->roles->grantPermission($id, $permission);
            }
            $ids[$key] = $id;
        }

        return $ids;
    }
}
