<?php

namespace App\Modules\Identity\Application\Federation;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Domain\Federation\FederationClaimAdmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offline post-verification boundary. A provider adapter must validate the
 * cryptographic assertion, nonce and issuer configuration before invoking it.
 * This class never creates a login session or binds an email to an account.
 */
final readonly class ConsumeVerifiedAssertion
{
    public function __construct(
        private FederationClaimAdmission $admission,
        private AuditRecorder $audit,
    ) {}

    public function execute(
        bool $signatureVerified,
        string $workspaceId,
        string $configuredWorkspaceId,
        string $configuredIssuer,
        string $assertionIssuer,
        string $configuredAudience,
        string $assertionAudience,
        string $subject,
        string $assertionId,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): string {
        if (strlen($subject) > 255 || $assertionId === '' || strlen($assertionId) > 255 || ! $this->admission->allows(
            $signatureVerified, $configuredWorkspaceId, $workspaceId, $configuredIssuer,
            $assertionIssuer, $configuredAudience, $assertionAudience, $subject,
            $issuedAt, $expiresAt, $now,
        )) {
            throw new AuthorizationException('Federation assertion denied.');
        }

        return DB::transaction(function () use ($workspaceId, $configuredIssuer, $subject, $assertionId, $expiresAt): string {
            $candidate = DB::table('federation_subject_bindings')
                ->where('workspace_id', $workspaceId)
                ->where('issuer', $configuredIssuer)
                ->where('subject', $subject)
                ->value('user_id');
            if ($candidate === null) {
                throw new AuthorizationException('Federation identity is not linked to an active workspace member.');
            }

            // Deactivation locks the user before deleting membership and subject
            // bindings. Serialize against it, then recheck both rows under lock.
            DB::table('users')->where('id', $candidate)->lockForUpdate()->firstOrFail();
            $binding = DB::table('federation_subject_bindings')
                ->join('workspace_memberships', function ($join): void {
                    $join->on('workspace_memberships.workspace_id', '=', 'federation_subject_bindings.workspace_id')
                        ->on('workspace_memberships.user_id', '=', 'federation_subject_bindings.user_id');
                })
                ->where('federation_subject_bindings.workspace_id', $workspaceId)
                ->where('federation_subject_bindings.issuer', $configuredIssuer)
                ->where('federation_subject_bindings.subject', $subject)
                ->where('federation_subject_bindings.user_id', $candidate)
                ->value('federation_subject_bindings.user_id');

            if ($binding === null) {
                throw new AuthorizationException('Federation identity is not linked to an active workspace member.');
            }

            $fingerprint = hash('sha256', $configuredIssuer."\0".$assertionId);
            $inserted = DB::table('federation_assertion_replays')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'assertion_fingerprint' => $fingerprint,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted !== 1) {
                throw new AuthorizationException('Federation assertion replay denied.');
            }

            $this->audit->record($workspaceId, 'identity.federation.assertion_consumed', [
                'assertion_fingerprint' => $fingerprint,
            ], actorId: (string) $binding, subjectType: 'user', subjectId: (string) $binding);

            return (string) $binding;
        });
    }
}
