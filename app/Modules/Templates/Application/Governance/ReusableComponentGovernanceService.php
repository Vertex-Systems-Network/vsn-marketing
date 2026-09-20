<?php

namespace App\Modules\Templates\Application\Governance;

use App\Modules\Templates\Domain\ComponentLifecycle;
use App\Modules\Templates\Domain\DefinitionKind;
use App\Modules\Templates\Domain\Governance\ReusableApprovalStatus;
use App\Modules\Templates\Domain\Governance\ReusableComponentGovernance;
use App\Modules\Templates\Domain\Governance\ReusableComponentScope;
use App\Modules\Templates\Domain\ReusableComponent;
use App\Modules\Templates\Domain\VersionedDefinition;
use DateTimeImmutable;
use InvalidArgumentException;

final class ReusableComponentGovernanceService
{
    /**
     * @param  array<string, mixed>  $auditProvenance
     */
    public function initialize(
        ReusableComponent $component,
        VersionedDefinition $version,
        ReusableComponentScope $scope,
        string $actorId,
        array $auditProvenance,
        DateTimeImmutable $at,
    ): ReusableComponentGovernance {
        self::assertVersionBelongsToComponent($component, $version);

        if ($at < $version->createdAt) {
            throw new InvalidArgumentException('Reusable governance cannot precede the governed component version.');
        }

        return ReusableComponentGovernance::initial(
            workspaceId: $component->workspaceId,
            componentId: $component->id,
            componentVersionId: $version->id,
            scope: $scope,
            actorId: $actorId,
            auditProvenance: $auditProvenance,
            at: $at,
        );
    }

    /**
     * @param  array<string, mixed>  $auditProvenance
     */
    public function transition(
        ReusableComponentGovernance $governance,
        ReusableComponent $component,
        VersionedDefinition $version,
        ReusableApprovalStatus $next,
        string $actorId,
        array $auditProvenance,
        DateTimeImmutable $at,
    ): ReusableComponentGovernance {
        self::assertExactGovernanceBinding($governance, $component, $version);

        if (
            in_array($next, [ReusableApprovalStatus::Ready, ReusableApprovalStatus::Approved], true)
            && $component->lifecycle !== ComponentLifecycle::Active
        ) {
            throw new InvalidArgumentException('Reusable component must be active before readiness or approval.');
        }

        if ($next === ReusableApprovalStatus::Approved && $version->status->isImmutable() === false) {
            throw new InvalidArgumentException('Reusable component approval requires an immutable exact component version.');
        }

        return $governance->transitionTo(
            next: $next,
            actorId: $actorId,
            auditProvenance: $auditProvenance,
            at: $at,
        );
    }

    /**
     * @param  list<VersionedDefinition>  $availableVersions
     */
    public function analyzeImpact(
        ReusableComponentGovernance $governance,
        ReusableComponent $component,
        VersionedDefinition $version,
        array $availableVersions,
    ): ReusableComponentImpact {
        self::assertExactGovernanceBinding($governance, $component, $version);

        $reverse = [];

        foreach ($availableVersions as $candidate) {
            if ($candidate instanceof VersionedDefinition === false) {
                throw new InvalidArgumentException('Reusable impact catalog must contain VersionedDefinition values.');
            }

            if ($candidate->workspaceId !== $governance->workspaceId) {
                continue;
            }

            foreach ($candidate->dependencies as $dependency) {
                if ($dependency->workspaceId !== $governance->workspaceId) {
                    throw new InvalidArgumentException('Reusable impact analysis encountered a cross-workspace dependency.');
                }

                $reverse[$dependency->versionId][] = $candidate->id;
            }
        }

        foreach ($reverse as &$dependents) {
            sort($dependents, SORT_STRING);
        }
        unset($dependents);

        $queue = [$version->id];
        $seen = [];
        $impacted = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($reverse[$current] ?? [] as $dependentId) {
                if (isset($seen[$dependentId])) {
                    continue;
                }

                $seen[$dependentId] = true;
                $impacted[] = $dependentId;
                $queue[] = $dependentId;
            }
        }

        sort($impacted, SORT_STRING);

        return new ReusableComponentImpact(
            workspaceId: $governance->workspaceId,
            componentId: $governance->componentId,
            componentVersionId: $governance->componentVersionId,
            scope: $governance->scope,
            approvalStatus: $governance->status(),
            impactedVersionIds: $impacted,
            requiresNewVersionForEdit: $governance->scope === ReusableComponentScope::Global
                || $governance->status()->protectsExactVersion()
                || $version->status->isImmutable(),
        );
    }

    /**
     * @param  list<\App\Modules\Templates\Domain\DependencyReference>  $dependencies
     */
    public function forkForEdit(
        ReusableComponentGovernance $governance,
        ReusableComponent $component,
        VersionedDefinition $version,
        string $newVersionId,
        array $dependencies,
        string $idempotencyKey,
        string $actorId,
        DateTimeImmutable $createdAt,
    ): VersionedDefinition {
        self::assertExactGovernanceBinding($governance, $component, $version);

        if ($governance->status() === ReusableApprovalStatus::Retired) {
            throw new InvalidArgumentException('Retired reusable component governance cannot be edited.');
        }

        if ($newVersionId === $version->id) {
            throw new InvalidArgumentException('Reusable component edit must create a new exact component version id.');
        }

        return $version->fork(
            id: $newVersionId,
            dependencies: $dependencies,
            idempotencyKey: $idempotencyKey,
            createdByActorId: $actorId,
            createdAt: $createdAt,
        );
    }

    private static function assertExactGovernanceBinding(
        ReusableComponentGovernance $governance,
        ReusableComponent $component,
        VersionedDefinition $version,
    ): void {
        self::assertVersionBelongsToComponent($component, $version);

        if (
            $governance->workspaceId !== $component->workspaceId
            || $governance->componentId !== $component->id
            || $governance->componentVersionId !== $version->id
        ) {
            throw new InvalidArgumentException('Reusable governance is not bound to the supplied exact component version.');
        }
    }

    private static function assertVersionBelongsToComponent(
        ReusableComponent $component,
        VersionedDefinition $version,
    ): void {
        if ($version->kind !== DefinitionKind::Component) {
            throw new InvalidArgumentException('Reusable governance requires a component version.');
        }

        if ($version->workspaceId !== $component->workspaceId) {
            throw new InvalidArgumentException('Reusable component version cannot cross workspaces.');
        }

        if ($version->ownerId !== $component->id) {
            throw new InvalidArgumentException('Reusable component version does not belong to the supplied component.');
        }
    }
}
