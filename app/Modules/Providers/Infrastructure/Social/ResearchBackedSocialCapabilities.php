<?php

namespace App\Modules\Providers\Infrastructure\Social;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Domain\Social\SocialCapability;
use App\Modules\Providers\Domain\Social\SocialOperation;
use App\Modules\Providers\Domain\Social\SocialPlatform;
use DateTimeImmutable;

final class ResearchBackedSocialCapabilities
{
    /** @return list<SocialCapability> */
    public static function candidates(): array
    {
        $observed = new DateTimeImmutable('2026-10-04T18:07:00+00:00');
        $freshUntil = new DateTimeImmutable('2026-11-04T18:07:00+00:00');
        $cap = static function (SocialPlatform $platform, SocialOperation $operation, string $provider, array $scopes, array $roles, array $constraints, string $source) use ($observed, $freshUntil): SocialCapability {
            return new SocialCapability($platform, $operation, $provider, CapabilitySupport::Supported, ProviderReadinessStatus::SandboxOnly, $scopes, $roles, $constraints, $source, $observed, $freshUntil);
        };

        return [
            $cap(SocialPlatform::LinkedIn, SocialOperation::Create, 'linkedin-posts', ['w_member_social'], [], ['organic_text'], 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09'),
            $cap(SocialPlatform::LinkedIn, SocialOperation::Create, 'linkedin-posts', ['w_organization_social'], ['ADMINISTRATOR'], ['organic_text'], 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09'),
            $cap(SocialPlatform::LinkedIn, SocialOperation::Status, 'linkedin-posts', ['r_member_social'], [], ['organic_post'], 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09'),
            $cap(SocialPlatform::LinkedIn, SocialOperation::Edit, 'linkedin-posts', ['w_member_social'], [], ['commentary_partial_update'], 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09'),
            $cap(SocialPlatform::LinkedIn, SocialOperation::Delete, 'linkedin-posts', ['w_member_social'], [], ['organic_post'], 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09'),
            $cap(SocialPlatform::TikTok, SocialOperation::Create, 'tiktok-content-posting', ['video.publish'], [], ['creator_consent', 'manual_privacy', 'audited_client'], 'https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post'),
            $cap(SocialPlatform::TikTok, SocialOperation::Status, 'tiktok-content-posting', ['video.publish'], [], ['publish_id_reconciliation'], 'https://developers.tiktok.com/docs/en/content-posting-api-reference-get-video-status'),
            $cap(SocialPlatform::YouTube, SocialOperation::Media, 'youtube-data-v3', ['https://www.googleapis.com/auth/youtube.upload'], [], ['unverified_private_only'], 'https://developers.google.com/youtube/v3/docs/videos/insert'),
            $cap(SocialPlatform::Threads, SocialOperation::Create, 'threads-api', ['threads_basic', 'threads_content_publish'], [], ['container_then_publish'], 'https://developers.facebook.com/documentation/threads/get-started'),
        ];
    }
}
