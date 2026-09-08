<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\DeliveryEngine\Domain\Contracts\RecipientSource;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\RecipientIdentity;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseRecipientSource implements RecipientSource
{
    public function __construct(private DatabaseManager $database) {}

    public function resolve(
        TenantContext $context,
        string $contactId,
        string $contactIdentityId,
        DeliveryChannel $channel,
    ): RecipientIdentity {
        $query = $this->database->connection()->table('contact_identities as identity')
            ->join('contacts as contact', function ($join): void {
                $join->on('contact.id', '=', 'identity.contact_id')
                    ->on('contact.workspace_id', '=', 'identity.workspace_id');
            })
            ->where('identity.workspace_id', $context->workspaceId)
            ->where('identity.id', $contactIdentityId)
            ->where('identity.contact_id', $contactId)
            ->where('identity.type', $channel->contactIdentityType());

        if ($context->brandId !== null) {
            $query->where('contact.brand_id', $context->brandId);
        }

        $row = $query->select([
            'identity.contact_id',
            'identity.id as contact_identity_id',
            'identity.type',
            'identity.value',
            'identity.normalized_value',
            'identity.provider',
            'identity.provider_reference',
            'identity.verified_at',
        ])->first();

        if (! $row instanceof stdClass) {
            throw new AuthorizationException('Recipient identity access denied.');
        }

        return new RecipientIdentity(
            contactId: (string) $row->contact_id,
            contactIdentityId: (string) $row->contact_identity_id,
            type: (string) $row->type,
            value: (string) $row->value,
            normalizedValue: (string) $row->normalized_value,
            provider: $row->provider === null ? null : (string) $row->provider,
            providerReference: $row->provider_reference === null ? null : (string) $row->provider_reference,
            verifiedAt: $row->verified_at === null ? null : (string) $row->verified_at,
        );
    }
}
