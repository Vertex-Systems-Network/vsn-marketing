<?php

namespace App\Modules\Templates\Application\Governance;

use App\Modules\Templates\Domain\Governance\ReusableApprovalStatus;
use App\Modules\Templates\Domain\Governance\ReusableComponentScope;
use InvalidArgumentException;

final readonly class ReusableComponentImpact
{
    /**
     * @param  list<string>  $impactedVersionIds
     */
    public function __construct(
        public string $workspaceId,
        public string $componentId,
        public string $componentVersionId,
        public ReusableComponentScope $scope,
        public ReusableApprovalStatus $approvalStatus,
        public array $impactedVersionIds,
        public bool $requiresNewVersionForEdit,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'componentId' => $this->componentId,
            'componentVersionId' => $this->componentVersionId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Reusable component impact {$field} must not be empty.");
            }
        }

        $previous = null;

        foreach ($this->impactedVersionIds as $versionId) {
            if (trim($versionId) === '') {
                throw new InvalidArgumentException('Impacted reusable component version id must not be empty.');
            }

            if ($previous !== null && strcmp($previous, $versionId) >= 0) {
                throw new InvalidArgumentException('Impacted reusable component version ids must be unique and sorted.');
            }

            $previous = $versionId;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'component_id' => $this->componentId,
            'component_version_id' => $this->componentVersionId,
            'scope' => $this->scope->value,
            'approval_status' => $this->approvalStatus->value,
            'impacted_version_ids' => $this->impactedVersionIds,
            'requires_new_version_for_edit' => $this->requiresNewVersionForEdit,
        ];
    }
}
