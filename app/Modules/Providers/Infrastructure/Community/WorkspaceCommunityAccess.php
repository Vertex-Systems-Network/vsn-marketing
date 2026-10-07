<?php

namespace App\Modules\Providers\Infrastructure\Community;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Community\CommunityAccess;

final readonly class WorkspaceCommunityAccess implements CommunityAccess
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function allows(TenantContext $actor, string $permission): bool
    {
        $user = User::query()->find($actor->actorId);

        return $user instanceof User && $this->authorizer->allows($user, $actor, $permission);
    }
}
