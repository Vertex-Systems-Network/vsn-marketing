<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditEventRepository;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;

final readonly class AuditRecorder
{
    public function __construct(
        private Clock $clock,
        private IdentifierGenerator $identifiers,
        private AuditEventRepository $events,
    ) {
    }

    public function record(
        string $workspaceId,
        string $action,
        array $evidence,
        ?string $brandId = null,
        ?string $actorId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $correlationId = null,
    ): AuditEvent {
        $event = new AuditEvent(
            id: $this->identifiers->next(),
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: $actorId,
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            evidence: $evidence,
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        );

        $this->events->store($event);

        return $event;
    }
}
