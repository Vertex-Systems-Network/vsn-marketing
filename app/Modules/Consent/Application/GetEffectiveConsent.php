<?php

namespace App\Modules\Consent\Application;

use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\ConsentRecord;
use App\Modules\Consent\Domain\ConsentValueNormalizer;
use App\Modules\Consent\Domain\Contracts\ConsentRecordRepository;
use App\Modules\Consent\Domain\EffectiveConsent;
use App\Modules\Consent\Domain\EffectiveConsentStatus;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class GetEffectiveConsent
{
    public function __construct(
        private ContactRepository $contacts,
        private ConsentRecordRepository $records,
        private ConsentValueNormalizer $normalizer,
    ) {}

    public function handle(
        TenantContext $context,
        string $contactId,
        string $channel,
        string $purpose,
    ): EffectiveConsent {
        $channel = $this->normalizer->channel($channel);
        $purpose = $this->normalizer->purpose($purpose);

        if ($this->contacts->find($context->workspaceId, $context->brandId, $contactId) === null) {
            throw new AuthorizationException('Contact access denied.');
        }

        $latest = $this->records->latestFor($context->workspaceId, $contactId, $channel, $purpose);
        if ($latest === []) {
            return new EffectiveConsent(EffectiveConsentStatus::Missing);
        }

        $decisions = array_values(array_unique(array_map(
            static fn (ConsentRecord $record): string => $record->decision->value,
            $latest,
        )));
        $representative = $latest[0];

        if (count($decisions) !== 1) {
            return new EffectiveConsent(
                status: EffectiveConsentStatus::Ambiguous,
                occurredAt: $representative->occurredAt,
            );
        }

        return new EffectiveConsent(
            status: $representative->decision === ConsentDecision::Granted
                ? EffectiveConsentStatus::Granted
                : EffectiveConsentStatus::Denied,
            recordId: $representative->id,
            occurredAt: $representative->occurredAt,
        );
    }
}
