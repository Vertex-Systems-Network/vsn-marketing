<?php

namespace App\Modules\Consent\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\ConsentRecord;
use App\Modules\Consent\Domain\ConsentValueNormalizer;
use App\Modules\Consent\Domain\Contracts\ConsentRecordRepository;
use App\Modules\Consent\Domain\Contracts\ConsentTransaction;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class RecordConsent
{
    public const AUDIT_ACTION = 'consent.recorded';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private Clock $clock,
        private ContactRepository $contacts,
        private ConsentRecordRepository $records,
        private ConsentValueNormalizer $normalizer,
        private AuditRecorder $audit,
        private ConsentTransaction $transaction,
    ) {}

    public function handle(
        TenantContext $context,
        string $contactId,
        string $channel,
        string $purpose,
        string $source,
        ConsentDecision $decision,
        ?DateTimeImmutable $occurredAt = null,
    ): ConsentRecord {
        $channel = $this->normalizer->channel($channel);
        $purpose = $this->normalizer->purpose($purpose);
        $source = $this->normalizer->source($source);
        $occurredAt ??= $this->clock->now();

        return $this->transaction->run(function () use (
            $context,
            $contactId,
            $channel,
            $purpose,
            $source,
            $decision,
            $occurredAt,
        ): ConsentRecord {
            $contact = $this->contacts->find($context->workspaceId, $context->brandId, $contactId);
            if ($contact === null) {
                throw new AuthorizationException('Contact access denied.');
            }

            $record = new ConsentRecord(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                contactId: $contactId,
                channel: $channel,
                purpose: $purpose,
                source: $source,
                decision: $decision,
                occurredAt: $occurredAt,
            );
            $this->records->append($record);

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $contact->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'contact',
                subjectId: $contactId,
                evidence: [
                    'consent_record_id' => $record->id,
                    'channel' => $channel,
                    'purpose' => $purpose,
                    'source' => $source,
                    'decision' => $decision->value,
                    'occurred_at' => $occurredAt->format(DATE_ATOM),
                ],
            );

            return $record;
        });
    }
}
