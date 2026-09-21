<?php

namespace App\Modules\Publishing\Application\Governance;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalEvaluation;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalInvalidReason;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignApprovalEvaluator
{
    public function __construct(
        private DatabaseCampaignRepository $campaigns,
        private ProviderRepository $providers,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
    ) {}

    public function evaluate(
        Campaign $campaign,
        CampaignSnapshot $snapshot,
        DateTimeImmutable $at,
    ): CampaignApprovalEvaluation {
        $this->assertExactBinding($campaign, $snapshot);

        $latest = $this->campaigns->latestSnapshot($campaign->workspaceId, $campaign->id);
        if ($latest === null || $latest->id !== $snapshot->id) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::MaterialRevision,
                detail: 'The approved snapshot is no longer the latest canonical campaign snapshot.',
            );
        }

        $decisions = $this->campaigns->approvalDecisions(
            $campaign->workspaceId,
            $campaign->id,
            $snapshot->id,
        );

        if ($decisions === []) {
            return CampaignApprovalEvaluation::invalid(CampaignApprovalInvalidReason::MissingApproval);
        }

        $decision = $decisions[array_key_last($decisions)];

        if ($decision->outcome === CampaignApprovalOutcome::Rejected) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ApprovalRejected,
                decisionId: $decision->id,
            );
        }

        if ($decision->outcome === CampaignApprovalOutcome::Revoked) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ApprovalRevoked,
                decisionId: $decision->id,
            );
        }

        if (! hash_equals($snapshot->targetSetHash, $decision->targetSetHash)) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::TargetSetChanged,
                decisionId: $decision->id,
            );
        }

        $expectedCapabilities = $snapshot->capabilityEvidenceIds;
        $approvedCapabilities = $decision->capabilityEvidenceIds;
        sort($expectedCapabilities, SORT_STRING);
        sort($approvedCapabilities, SORT_STRING);

        if ($expectedCapabilities !== $approvedCapabilities) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::CapabilityIncompatible,
                decisionId: $decision->id,
                detail: 'Approval capability evidence no longer matches the immutable snapshot.',
            );
        }

        if ($decision->expiresAt !== null && $decision->expiresAt <= $at) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ApprovalExpired,
                decisionId: $decision->id,
            );
        }

        $authority = $this->evaluateApproverAuthority($decision);
        if (! $authority->valid) {
            return new CampaignApprovalEvaluationProxy($authority, $decision->id)->value();
        }

        $snapshotAuthority = $this->evaluateSnapshotAuthority($snapshot, $at);
        if (! $snapshotAuthority->valid) {
            return new CampaignApprovalEvaluationProxy($snapshotAuthority, $decision->id)->value();
        }

        return CampaignApprovalEvaluation::valid($decision->id);
    }

    public function evaluateSnapshotAuthority(
        CampaignSnapshot $snapshot,
        DateTimeImmutable $at,
    ): CampaignApprovalEvaluation {
        $snapshotCapabilityIds = array_fill_keys($snapshot->capabilityEvidenceIds, true);

        foreach ($snapshot->capabilityEvidenceIds as $capabilityId) {
            $capability = $this->providers->findCapability($snapshot->workspaceId, $capabilityId);

            if ($capability === null) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::CapabilityMissing,
                    detail: $capabilityId,
                );
            }

            if ($capability->support !== CapabilitySupport::Supported) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::CapabilityUnsupported,
                    detail: $capabilityId,
                );
            }

            if ($capability->freshUntil !== null && $capability->freshUntil <= $at) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::CapabilityStale,
                    detail: $capabilityId,
                );
            }

            if ($capability->connectionId !== null) {
                $connectionEvaluation = $this->evaluateConnection(
                    $snapshot->workspaceId,
                    $capability->connectionId,
                    $capability->providerId,
                    $capability->requiredScopes,
                    $capability->requiredRoles,
                    $at,
                );

                if (! $connectionEvaluation->valid) {
                    return $connectionEvaluation;
                }
            }
        }

        foreach ($snapshot->targets as $target) {
            if ($target->kind !== CampaignTargetKind::ProviderConnection) {
                continue;
            }

            if (
                $target->providerConnectionId === null
                || $target->capabilityEvidenceId === null
                || ! isset($snapshotCapabilityIds[$target->capabilityEvidenceId])
            ) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::CapabilityMissing,
                    detail: $target->id,
                );
            }

            $capability = $this->providers->findCapability(
                $snapshot->workspaceId,
                $target->capabilityEvidenceId,
            );
            $connection = $this->providers->findConnection(
                $snapshot->workspaceId,
                $target->providerConnectionId,
            );

            if ($capability === null) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::CapabilityMissing,
                    detail: $target->capabilityEvidenceId,
                );
            }

            if ($connection === null) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::ConnectionUnavailable,
                    detail: $target->providerConnectionId,
                );
            }

            if (
                $capability->providerId !== $connection->providerId
                || ($capability->connectionId !== null && $capability->connectionId !== $connection->id)
            ) {
                return CampaignApprovalEvaluation::invalid(
                    CampaignApprovalInvalidReason::CapabilityIncompatible,
                    detail: $target->capabilityEvidenceId,
                );
            }

            $connectionEvaluation = $this->evaluateConnection(
                $snapshot->workspaceId,
                $connection->id,
                $capability->providerId,
                $capability->requiredScopes,
                $capability->requiredRoles,
                $at,
            );

            if (! $connectionEvaluation->valid) {
                return $connectionEvaluation;
            }
        }

        return CampaignApprovalEvaluation::valid();
    }

    private function evaluateApproverAuthority(
        CampaignApprovalDecision $decision,
    ): CampaignApprovalEvaluation {
        $user = User::query()->find($decision->actorId);
        $organizationId = $this->database->connection()->table('workspaces')
            ->where('id', $decision->workspaceId)
            ->value('organization_id');

        if (! $user instanceof User || $organizationId === null) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ApproverAuthorizationRevoked,
            );
        }

        $context = new TenantContext(
            organizationId: (string) $organizationId,
            workspaceId: $decision->workspaceId,
            brandId: null,
            actorId: $decision->actorId,
        );

        if (! $this->authorizer->allows($user, $context, PermissionCatalog::CAMPAIGN_APPROVE)) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ApproverAuthorizationRevoked,
            );
        }

        $roleStillAssigned = $this->database->connection()->table('workspace_memberships')
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
            ->where('workspace_memberships.workspace_id', $decision->workspaceId)
            ->where('workspace_memberships.user_id', $decision->actorId)
            ->where('workspace_roles.key', $decision->actorRole)
            ->where('workspace_role_permissions.permission', PermissionCatalog::CAMPAIGN_APPROVE)
            ->exists();

        return $roleStillAssigned
            ? CampaignApprovalEvaluation::valid($decision->id)
            : CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ApproverRoleRevoked,
                decisionId: $decision->id,
            );
    }

    /**
     * @param  list<string>  $requiredScopes
     * @param  list<string>  $requiredRoles
     */
    private function evaluateConnection(
        string $workspaceId,
        string $connectionId,
        string $providerId,
        array $requiredScopes,
        array $requiredRoles,
        DateTimeImmutable $at,
    ): CampaignApprovalEvaluation {
        $connection = $this->providers->findConnection($workspaceId, $connectionId);

        if (
            $connection === null
            || $connection->providerId !== $providerId
            || $connection->readiness !== ProviderReadinessStatus::Ready
        ) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ConnectionUnavailable,
                detail: $connectionId,
            );
        }

        if (
            ($connection->freshUntil !== null && $connection->freshUntil <= $at)
            || ($connection->tokenExpiresAt !== null && $connection->tokenExpiresAt <= $at)
        ) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ConnectionStale,
                detail: $connectionId,
            );
        }

        if (array_diff($requiredScopes, $connection->grantedScopes) !== []) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ConnectionScopeRevoked,
                detail: $connectionId,
            );
        }

        if (array_diff($requiredRoles, $connection->roles) !== []) {
            return CampaignApprovalEvaluation::invalid(
                CampaignApprovalInvalidReason::ConnectionRoleRevoked,
                detail: $connectionId,
            );
        }

        return CampaignApprovalEvaluation::valid();
    }

    private function assertExactBinding(Campaign $campaign, CampaignSnapshot $snapshot): void
    {
        if (
            $campaign->workspaceId !== $snapshot->workspaceId
            || $campaign->id !== $snapshot->campaignId
        ) {
            throw new InvalidArgumentException('Campaign approval evaluation requires an exact campaign snapshot binding.');
        }
    }
}
