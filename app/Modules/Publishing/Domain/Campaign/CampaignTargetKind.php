<?php

namespace App\Modules\Publishing\Domain\Campaign;

enum CampaignTargetKind: string
{
    case ProviderConnection = 'provider_connection';
    case ContactIdentity = 'contact_identity';
    case Contact = 'contact';
    case ContactList = 'contact_list';
    case Tag = 'tag';
}
