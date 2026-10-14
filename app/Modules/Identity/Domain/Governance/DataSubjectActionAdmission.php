<?php

namespace App\Modules\Identity\Domain\Governance;

final class DataSubjectActionAdmission
{
    public function allows(
        string $requestWorkspaceId,
        string $recordWorkspaceId,
        string $action,
        bool $identityVerified,
        bool $authorizedOperator,
        bool $legalHoldActive,
        bool $downstreamInventoryComplete,
    ): bool {
        if ($requestWorkspaceId === '' || $recordWorkspaceId === '' || ! hash_equals($requestWorkspaceId, $recordWorkspaceId)
            || ! $identityVerified || ! $authorizedOperator || ! $downstreamInventoryComplete) {
            return false;
        }

        return match ($action) {
            'export' => true,
            'erase', 'retention_expire' => ! $legalHoldActive,
            default => false,
        };
    }
}
