<?php

namespace App\Modules\Audit\Infrastructure;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditEventRepository;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseAuditEventRepository implements AuditEventRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function store(AuditEvent $event): void
    {
        $this->database->connection()->table('audit_events')->insert([
            'id' => $event->id,
            'workspace_id' => $event->workspaceId,
            'brand_id' => $event->brandId,
            'actor_id' => $event->actorId,
            'action' => $event->action,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'evidence' => json_encode($event->evidence, JSON_THROW_ON_ERROR),
            'correlation_id' => $event->correlationId,
            'occurred_at' => $event->occurredAt,
            'created_at' => $event->occurredAt,
        ]);
    }
}
