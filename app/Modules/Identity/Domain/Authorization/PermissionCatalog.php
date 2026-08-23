<?php

namespace App\Modules\Identity\Domain\Authorization;

final class PermissionCatalog
{
    public const CONTACT_READ = 'contact.read';

    public const CONTACT_WRITE = 'contact.write';

    public const CAMPAIGN_READ = 'campaign.read';

    public const CAMPAIGN_CREATE = 'campaign.create';

    public const CAMPAIGN_APPROVE = 'campaign.approve';

    public const CAMPAIGN_SEND = 'campaign.send';

    public const TEMPLATE_CREATE = 'template.create';

    public const TEMPLATE_PUBLISH = 'template.publish';

    public const PROVIDER_CREATE = 'provider.create';

    public const PROVIDER_CREDENTIALS_MANAGE = 'provider.credentials.manage';

    public const AI_EXECUTE = 'ai.execute';

    public const AI_APPROVE = 'ai.approve';

    public const ANALYTICS_READ = 'analytics.read';

    public const BILLING_MANAGE = 'billing.manage';

    public static function all(): array
    {
        return [
            self::CONTACT_READ,
            self::CONTACT_WRITE,
            self::CAMPAIGN_READ,
            self::CAMPAIGN_CREATE,
            self::CAMPAIGN_APPROVE,
            self::CAMPAIGN_SEND,
            self::TEMPLATE_CREATE,
            self::TEMPLATE_PUBLISH,
            self::PROVIDER_CREATE,
            self::PROVIDER_CREDENTIALS_MANAGE,
            self::AI_EXECUTE,
            self::AI_APPROVE,
            self::ANALYTICS_READ,
            self::BILLING_MANAGE,
        ];
    }

    public static function contains(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
