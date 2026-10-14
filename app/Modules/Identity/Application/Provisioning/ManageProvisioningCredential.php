<?php

namespace App\Modules\Identity\Application\Provisioning;

use App\Modules\Audit\Application\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ManageProvisioningCredential
{
    public function __construct(private AuditRecorder $audit) {}

    /** @return array{id: string, token: string, expires_at: string} */
    public function issue(string $workspaceId, string $actorId, ?string $replacedId = null): array
    {
        return DB::transaction(function () use ($workspaceId, $actorId, $replacedId): array {
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->firstOrFail();
            if ($replacedId !== null) {
                $updated = DB::table('workspace_provisioning_credentials')
                    ->where('workspace_id', $workspaceId)->where('id', $replacedId)->where('enabled', true)
                    ->update(['enabled' => false, 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new AuthorizationException('Provisioning credential denied.');
                }
            } elseif (DB::table('workspace_provisioning_credentials')->where('workspace_id', $workspaceId)
                ->where('enabled', true)->where('expires_at', '>', now())->exists()) {
                throw new AuthorizationException('Rotate the existing credential.');
            }
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $expiry = now()->addDays(30);
            DB::table('workspace_provisioning_credentials')->insert([
                'id' => $id, 'workspace_id' => $workspaceId, 'secret_hash' => hash('sha256', $token),
                'enabled' => true, 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($workspaceId, $replacedId === null ? 'identity.provisioning.credential_issued' : 'identity.provisioning.credential_rotated',
                ['credential_id' => $id, 'replaced_credential_id' => $replacedId], actorId: $actorId,
                subjectType: 'provisioning_credential', subjectId: $id);

            return ['id' => $id, 'token' => $token, 'expires_at' => $expiry->toIso8601String()];
        });
    }

    public function revoke(string $workspaceId, string $actorId, string $credentialId): void
    {
        DB::transaction(function () use ($workspaceId, $actorId, $credentialId): void {
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->firstOrFail();
            $updated = DB::table('workspace_provisioning_credentials')
                ->where('workspace_id', $workspaceId)->where('id', $credentialId)->where('enabled', true)
                ->update(['enabled' => false, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new AuthorizationException('Provisioning credential denied.');
            }
            $this->audit->record($workspaceId, 'identity.provisioning.credential_revoked',
                ['credential_id' => $credentialId], actorId: $actorId,
                subjectType: 'provisioning_credential', subjectId: $credentialId);
        });
    }
}
