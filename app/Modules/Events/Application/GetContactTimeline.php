<?php

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Events\Domain\Contracts\CustomerEventRepository;
use App\Modules\Events\Domain\Contracts\CustomerEventSubjectResolver;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class GetContactTimeline
{
    public function __construct(
        private CustomerEventRepository $events,
        private CustomerEventSubjectResolver $subjects,
    ) {}

    /** @return list<CanonicalEvent> */
    public function handle(TenantContext $context, string $contactId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Contact timeline limit must be between 1 and 200.');
        }

        $this->subjects->resolve(
            workspaceId: $context->workspaceId,
            brandScopeId: $context->brandId,
            contactId: $contactId,
            contactIdentityId: null,
        );

        return $this->events->timeline(
            workspaceId: $context->workspaceId,
            brandScopeId: $context->brandId,
            contactId: $contactId,
            limit: $limit,
        );
    }
}
