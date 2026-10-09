<?php

namespace App\Modules\AI\Application;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Human-session-only append-only OFFLINE approval recorder.
 *
 * This does not authorize execution or expose a route. Any future route must
 * perform its own authentication, CSRF and snapshot retrieval. Approval
 * validity is independently rechecked by DatabaseBoundedAutonomyApprovalSource.
 */
final readonly class BoundedAutonomyHumanApprovalRecorder
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function record(
        User $approver,
        TenantContext $requester,
        array $preview,
        array $binding,
        string $outcome,
        DateTimeImmutable $at,
    ): array {
        $approverId = (string) $approver->getKey();
        if ((string) Auth::id() !== $approverId
            || $approverId === $requester->actorId
            || ! in_array($outcome, ['approved', 'rejected', 'revoked'], true)
            || ($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $requester->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! self::identifier($preview['run_id'] ?? null)
            || ! self::identifier($preview['policy_version'] ?? null)
            || ! self::digest($preview['snapshot_sha256'] ?? null)) {
            throw new InvalidArgumentException('Independent authenticated approval or trusted preview required.');
        }

        $expected = [
            'audience_sha256', 'content_sha256', 'destination_sha256',
            'max_cost_minor', 'max_volume', 'not_before_unix', 'expires_at_unix',
        ];
        if (count($binding) !== count($expected)
            || array_diff(array_keys($binding), $expected) !== []
            || array_diff($expected, array_keys($binding)) !== []) {
            throw new InvalidArgumentException('Untrusted approval binding shape.');
        }
        foreach (['audience_sha256', 'content_sha256', 'destination_sha256'] as $field) {
            if (! self::digest($binding[$field])) {
                throw new InvalidArgumentException('Invalid immutable approval reference.');
            }
        }
        if (! is_int($binding['max_cost_minor']) || $binding['max_cost_minor'] < 0
            || $binding['max_cost_minor'] > 1000000000
            || ! is_int($binding['max_volume']) || $binding['max_volume'] < 0
            || $binding['max_volume'] > 1000000
            || ! is_int($binding['not_before_unix'])
            || ! is_int($binding['expires_at_unix'])
            || $binding['not_before_unix'] > $at->getTimestamp()
            || $binding['expires_at_unix'] <= $at->getTimestamp()
            || $binding['expires_at_unix'] > $at->getTimestamp() + 86400) {
            throw new InvalidArgumentException('Invalid bounded human approval request.');
        }

        $approverScope = new TenantContext(
            $requester->organizationId, $requester->workspaceId,
            $requester->brandId, $approverId,
        );
        if (! $this->authorizer->allows($approver, $approverScope, PermissionCatalog::AI_APPROVE)) {
            throw new InvalidArgumentException('Current independent workspace approval authority missing.');
        }

        return DB::transaction(function () use ($approverId, $requester, $preview, $binding, $outcome, $at): array {
            // No approval may invent a predecessor to revoke. A current
            // authorized reviewer must still validate the *latest* decision.
            if ($outcome === 'revoked' && ! DB::table('ai_autonomy_offline_approval_decisions')
                ->where('workspace_id', $requester->workspaceId)
                ->where('run_id', $preview['run_id'])->exists()) {
                throw new InvalidArgumentException('Cannot revoke missing approval evidence.');
            }

            $id = (string) Str::uuid();
            DB::table('ai_autonomy_offline_approval_decisions')->insert([
                'decision_id' => $id,
                'workspace_id' => $requester->workspaceId,
                'brand_id' => $requester->brandId,
                'run_id' => $preview['run_id'],
                'snapshot_sha256' => $preview['snapshot_sha256'],
                'policy_version' => $preview['policy_version'],
                'audience_sha256' => $binding['audience_sha256'],
                'content_sha256' => $binding['content_sha256'],
                'destination_sha256' => $binding['destination_sha256'],
                'max_cost_minor' => $binding['max_cost_minor'],
                'max_volume' => $binding['max_volume'],
                'not_before_unix' => $binding['not_before_unix'],
                'expires_at_unix' => $binding['expires_at_unix'],
                'approved_at_unix' => $at->getTimestamp(),
                'approver_id' => $approverId,
                'outcome' => $outcome,
                'created_at' => now(),
            ]);

            return [
                'status' => 'decision_recorded_offline',
                'decision_id' => $id,
                'outcome' => $outcome,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }, 3);
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }
}
