<?php

namespace App\Modules\Identity\Application\Sessions;

use App\Modules\Audit\Application\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Durable issuance ordering; all increments and revocations serialize on the user row. */
final readonly class WorkspaceSessionPolicy
{
    public function __construct(private AuditRecorder $audit) {}

    public function issue(string $userId): int
    {
        return DB::transaction(function () use ($userId): int {
            DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
            DB::table('identity_session_issuances')->insertOrIgnore([
                'user_id' => $userId, 'generation' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $generation = (int) DB::table('identity_session_issuances')->where('user_id', $userId)->value('generation') + 1;
            DB::table('identity_session_issuances')->where('user_id', $userId)
                ->update(['generation' => $generation, 'updated_at' => now()]);

            return $generation;
        });
    }

    public function permits(string $userId, string $workspaceId, mixed $sessionGeneration): bool
    {
        $revoked = DB::table('workspace_session_revocations')
            ->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->value('revoked_generation');

        return $revoked === null || (is_int($sessionGeneration) && $sessionGeneration > (int) $revoked);
    }

    public function revoke(string $workspaceId, string $userId, string $actorId): void
    {
        DB::transaction(function () use ($workspaceId, $userId, $actorId): void {
            DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
            if (! DB::table('workspace_memberships')->where('workspace_id', $workspaceId)
                ->where('user_id', $userId)->exists()) {
                throw new AuthorizationException('Member is not in the workspace.');
            }
            $this->recordRevocation($workspaceId, $userId, $actorId);
        });
    }

    /** Caller holds the target user row lock and a database transaction. */
    public function recordRevocation(string $workspaceId, string $userId, ?string $actorId): void
    {
        $generation = (int) (DB::table('identity_session_issuances')
            ->where('user_id', $userId)->value('generation') ?? 0);
        DB::table('workspace_session_revocations')->updateOrInsert(
            ['workspace_id' => $workspaceId, 'user_id' => $userId],
            ['revoked_generation' => $generation, 'created_at' => now(), 'updated_at' => now()],
        );
        $this->audit->record($workspaceId, 'identity.session.workspace_revoked', [
            'revoked_generation' => $generation,
        ], actorId: $actorId, subjectType: 'user', subjectId: $userId);
    }
}
