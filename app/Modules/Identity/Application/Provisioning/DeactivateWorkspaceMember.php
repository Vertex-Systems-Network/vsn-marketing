<?php

namespace App\Modules\Identity\Application\Provisioning;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Sessions\WorkspaceSessionPolicy;
use App\Modules\Identity\Domain\Provisioning\ScimProvisioningAdmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Workspace-scoped provisioning boundary. The narrow SCIM adapter is disabled by default.
 */
final readonly class DeactivateWorkspaceMember
{
    public function __construct(
        private ScimProvisioningAdmission $admission,
        private AuditRecorder $audit,
        private WorkspaceSessionPolicy $sessions,
    ) {}

    public function execute(
        string $principalWorkspaceId,
        string $workspaceId,
        string $userId,
        bool $principalAuthenticated,
        bool $provisioningEnabled,
        array $grantedActions,
        string $principalReference,
    ): bool {
        if ($userId === '' || $principalReference === '' || strlen($principalReference) > 256 || ! $this->admission->allows(
            $principalWorkspaceId,
            $workspaceId,
            'user.deactivate',
            $grantedActions,
            $principalAuthenticated,
            $provisioningEnabled,
        )) {
            throw new AuthorizationException('Provisioning action denied.');
        }

        return DB::transaction(function () use ($workspaceId, $userId, $principalReference): bool {
            DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
            $membership = DB::table('workspace_memberships')
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return false;
            }

            $this->sessions->recordRevocation($workspaceId, $userId, null);
            DB::table('federation_subject_bindings')
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $userId)
                ->delete();
            DB::table('workspace_memberships')->where('id', $membership->id)->delete();

            $this->audit->record(
                workspaceId: $workspaceId,
                action: 'identity.provisioning.member_deactivated',
                evidence: ['principal_fingerprint' => hash('sha256', $principalReference)],
                subjectType: 'user',
                subjectId: $userId,
            );

            return true;
        });
    }
}
