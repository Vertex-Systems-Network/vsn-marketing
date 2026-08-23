<?php

namespace App\Modules\Events\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Events\Domain\Contracts\CustomerEventRepository;
use App\Modules\Events\Domain\Contracts\CustomerEventSubjectResolver;
use App\Modules\Events\Domain\Contracts\EventTransaction;
use App\Modules\Events\Domain\Contracts\EventTypeRepository;
use App\Modules\Events\Domain\CustomerEventPersistenceResult;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class PersistCustomerEvent
{
    public const AUDIT_ACTION = 'customer_event.persisted';

    public function __construct(
        private EventTypeRepository $eventTypes,
        private CustomerEventRepository $events,
        private CustomerEventSubjectResolver $subjects,
        private AuditRecorder $audit,
        private EventTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, CanonicalEvent $event): CustomerEventPersistenceResult
    {
        if ($event->workspaceId !== $context->workspaceId) {
            throw new AuthorizationException('Customer event workspace access denied.');
        }

        $contactId = $event->subjects['contact_id'] ?? null;
        if (! is_string($contactId) || $contactId === '') {
            throw new InvalidArgumentException('Customer event requires canonical contact_id subject.');
        }
        $contactIdentityId = $event->subjects['contact_identity_id'] ?? null;
        if ($contactIdentityId !== null && (! is_string($contactIdentityId) || $contactIdentityId === '')) {
            throw new InvalidArgumentException('contact_identity_id subject must be a non-empty string when present.');
        }

        return $this->transaction->run(function () use ($context, $event, $contactId, $contactIdentityId): CustomerEventPersistenceResult {
            $subject = $this->subjects->resolve(
                workspaceId: $context->workspaceId,
                brandScopeId: $context->brandId,
                contactId: $contactId,
                contactIdentityId: $contactIdentityId,
            );
            if ($event->brandId !== $subject->brandId) {
                throw new AuthorizationException('Customer event brand scope denied.');
            }

            $eventType = $this->eventTypes->ensure(
                workspaceId: $event->workspaceId,
                canonicalName: $event->eventType,
                schemaVersion: $event->schemaVersion,
            );
            $inserted = $this->events->store($eventType, $event, $subject);

            if ($inserted) {
                $this->audit->record(
                    workspaceId: $event->workspaceId,
                    brandId: $event->brandId,
                    actorId: $context->actorId,
                    action: self::AUDIT_ACTION,
                    subjectType: 'contact',
                    subjectId: $contactId,
                    evidence: [
                        'event_id' => $event->eventId,
                        'event_type' => $event->eventType,
                        'schema_version' => $event->schemaVersion,
                        'source' => $event->source,
                        'has_contact_identity' => $contactIdentityId !== null,
                    ],
                );
            }

            return new CustomerEventPersistenceResult($event, $eventType, $inserted);
        });
    }
}
