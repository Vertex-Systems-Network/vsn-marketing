<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Governance\CampaignGovernanceService;
use App\Modules\Publishing\Domain\Campaign\Campaign;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class PublishingOperatorActionService
{
    public function __construct(
        private CampaignGovernanceService $governance,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
    ) {}

    public function approve(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        int $stateVersion,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        return $this->guardedApprovalMutation(
            $actor,
            $context,
            $campaignId,
            $snapshotId,
            $stateVersion,
            function (string $roleKey) use ($actor, $context, $campaignId, $snapshotId, $reason, $at): Campaign {
                return $this->governance->approve(
                    $actor,
                    $context,
                    $campaignId,
                    $snapshotId,
                    $roleKey,
                    (string) Str::uuid(),
                    $this->idempotency('approve', 'decision'),
                    (string) Str::uuid(),
                    $this->idempotency('approve', 'approval-event'),
                    (string) Str::uuid(),
                    $this->idempotency('approve', 'transition-event'),
                    $reason,
                    null,
                    $at,
                );
            },
        );
    }

    public function reject(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        int $stateVersion,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        return $this->guardedApprovalMutation(
            $actor,
            $context,
            $campaignId,
            $snapshotId,
            $stateVersion,
            function (string $roleKey) use ($actor, $context, $campaignId, $snapshotId, $reason, $at): Campaign {
                return $this->governance->reject(
                    $actor,
                    $context,
                    $campaignId,
                    $snapshotId,
                    $roleKey,
                    (string) Str::uuid(),
                    $this->idempotency('reject', 'decision'),
                    (string) Str::uuid(),
                    $this->idempotency('reject', 'approval-event'),
                    (string) Str::uuid(),
                    $this->idempotency('reject', 'transition-event'),
                    $reason,
                    $at,
                );
            },
        );
    }

    public function revoke(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        int $stateVersion,
        ?string $reason,
        DateTimeImmutable $at,
    ): Campaign {
        return $this->guardedApprovalMutation(
            $actor,
            $context,
            $campaignId,
            $snapshotId,
            $stateVersion,
            function (string $roleKey) use ($actor, $context, $campaignId, $reason, $at): Campaign {
                return $this->governance->revokeApproval(
                    $actor,
                    $context,
                    $campaignId,
                    $roleKey,
                    (string) Str::uuid(),
                    $this->idempotency('revoke', 'decision'),
                    (string) Str::uuid(),
                    $this->idempotency('revoke', 'approval-event'),
                    (string) Str::uuid(),
                    $this->idempotency('revoke', 'transition-event'),
                    $reason,
                    $at,
                );
            },
        );
    }

    /**
     * @param  callable(string): Campaign  $mutation
     */
    private function guardedApprovalMutation(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        int $stateVersion,
        callable $mutation,
    ): Campaign {
        if ((string) $actor->getKey() !== $context->actorId) {
            throw new AuthorizationException('Publishing operator actor context does not match authentication.');
        }

        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::CAMPAIGN_APPROVE)) {
            throw new AuthorizationException('Campaign approval permission is required.');
        }

        return $this->database->connection()->transaction(function () use (
            $actor,
            $context,
            $campaignId,
            $snapshotId,
            $stateVersion,
            $mutation,
        ): Campaign {
            $campaign = $this->database->connection()->table('campaigns')
                ->select(['id', 'state_version'])
                ->where('workspace_id', $context->workspaceId)
                ->where('id', $campaignId)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof stdClass) {
                throw new AuthorizationException('Campaign workspace access denied.');
            }

            if ((int) $campaign->state_version !== $stateVersion) {
                throw new InvalidArgumentException('Campaign state changed; refresh before applying an approval action.');
            }

            $latestSnapshotId = $this->database->connection()->table('campaign_snapshots')
                ->where('workspace_id', $context->workspaceId)
                ->where('campaign_id', $campaignId)
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->value('id');

            if (! is_string($latestSnapshotId) || $latestSnapshotId === '') {
                throw new InvalidArgumentException('Campaign does not have an approval-eligible snapshot.');
            }

            if (! hash_equals($latestSnapshotId, $snapshotId)) {
                throw new InvalidArgumentException('Campaign snapshot changed; refresh before applying an approval action.');
            }

            return $mutation($this->approvalRoleKey($actor, $context));
        });
    }

    private function approvalRoleKey(User $actor, TenantContext $context): string
    {
        $roleKey = $this->database->connection()->table('workspace_memberships')
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
            ->where('workspace_memberships.user_id', $actor->getKey())
            ->where('workspace_roles.workspace_id', $context->workspaceId)
            ->where('workspace_role_permissions.permission', PermissionCatalog::CAMPAIGN_APPROVE)
            ->orderBy('workspace_roles.key')
            ->value('workspace_roles.key');

        if (! is_string($roleKey) || trim($roleKey) === '') {
            throw new AuthorizationException('Campaign approval role is not currently assigned.');
        }

        return $roleKey;
    }

    private function idempotency(string $action, string $part): string
    {
        return 'task0041-operator-'.$action.'-'.$part.'-'.Str::uuid();
    }
}
