<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

final class WorkspaceAiContextPermission implements AiContextPermission
{
    public function __construct(private readonly WorkspaceAuthorizer $authorizer, private readonly User $actor) {}

    public function allows(TenantContext $scope, string $permission): bool
    {
        return $this->authorizer->allows($this->actor, $scope, $permission);
    }
}
