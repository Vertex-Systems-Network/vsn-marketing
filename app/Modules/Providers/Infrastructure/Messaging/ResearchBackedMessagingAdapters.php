<?php

namespace App\Modules\Providers\Infrastructure\Messaging;

use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateSuppressionAwareEligibility;
use App\Modules\Providers\Application\Messaging\EvaluateMessagingIntent;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Messaging\MessagingCapability;
use App\Modules\Providers\Domain\Messaging\MessagingChannel;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;

final class ResearchBackedMessagingAdapters
{
    /** @return list<OfflineMessagingAdapter> */
    public static function candidates(): array
    {
        $gate = new EvaluateMessagingIntent(new EvaluateSuppressionAwareEligibility(new EvaluateDeliveryEligibility));
        $observed = new DateTimeImmutable('2026-10-04T00:37:00+00:00');
        // Stale provenance fails closed after this research window; refresh from official docs.
        $freshUntil = new DateTimeImmutable('2026-11-04T00:37:00+00:00');

        return [
            new OfflineMessagingAdapter(new MessagingCapability(MessagingChannel::Sms, 'azure-communication-services', CapabilitySupport::Supported, ProviderReadinessStatus::SandboxOnly, ['sms.send'], 'https://learn.microsoft.com/en-us/azure/communication-services/concepts/sms/concepts', $observed, $freshUntil), $gate),
            new OfflineMessagingAdapter(new MessagingCapability(MessagingChannel::WhatsApp, 'meta-whatsapp-cloud', CapabilitySupport::Supported, ProviderReadinessStatus::SandboxOnly, ['whatsapp_business_messaging'], 'https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api', $observed, $freshUntil), $gate),
            new OfflineMessagingAdapter(new MessagingCapability(MessagingChannel::Rcs, 'google-rcs-business', CapabilitySupport::Supported, ProviderReadinessStatus::SandboxOnly, ['https://www.googleapis.com/auth/rcsbusinessmessaging'], 'https://developers.google.com/business-communications/rcs-business-messaging/reference/rest/v1/phones.agentMessages/create', $observed, $freshUntil), $gate),
            new OfflineMessagingAdapter(new MessagingCapability(MessagingChannel::Push, 'firebase-cloud-messaging', CapabilitySupport::Supported, ProviderReadinessStatus::SandboxOnly, ['https://www.googleapis.com/auth/firebase.messaging'], 'https://firebase.google.com/docs/cloud-messaging/send/v1-api', $observed, $freshUntil), $gate),
            new OfflineMessagingAdapter(new MessagingCapability(MessagingChannel::InApp, 'vsn-first-party', CapabilitySupport::Supported, ProviderReadinessStatus::SandboxOnly, ['in_app.write'], 'https://github.com/Vertex-Systems-Network/vsn-marketing/blob/main/.ai/00-PROJECT-CHARTER.md', $observed, $freshUntil), $gate),
        ];
    }
}
