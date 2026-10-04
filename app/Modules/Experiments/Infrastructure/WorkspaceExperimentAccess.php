<?php

namespace App\Modules\Experiments\Infrastructure;

use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

final readonly class WorkspaceExperimentAccess implements ExperimentAccess
{
    public function __construct(private WorkspaceAuthorizer $authorizer, private User $actor) {}

    public function allows(TenantContext $actor, string $permission): bool
    {
        return $this->authorizer->allows($this->actor, $actor, $permission);
    }
}
