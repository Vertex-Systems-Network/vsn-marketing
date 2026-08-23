<?php

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Events\Domain\CustomerEventSubject;

interface CustomerEventSubjectResolver
{
    public function resolve(
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        ?string $contactIdentityId,
    ): CustomerEventSubject;
}
