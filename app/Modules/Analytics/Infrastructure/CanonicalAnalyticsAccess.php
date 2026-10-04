<?php

namespace App\Modules\Analytics\Infrastructure;

use App\Modules\Analytics\Domain\AnalyticsAccess;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

final readonly class CanonicalAnalyticsAccess implements AnalyticsAccess
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function allows(TenantContext $actor, string $permission): bool
    {
        $user = User::query()->find($actor->actorId);

        return $user instanceof User && $this->authorizer->allows($user, $actor, $permission);
    }
}
