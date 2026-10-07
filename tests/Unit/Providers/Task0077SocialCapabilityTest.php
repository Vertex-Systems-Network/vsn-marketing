<?php

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Providers\Domain\Social\SocialCapability;
use App\Modules\Providers\Domain\Social\SocialOperation;
use App\Modules\Providers\Domain\Social\SocialPlatform;
use App\Modules\Providers\Infrastructure\Social\ResearchBackedSocialCapabilities;

it('exposes only researched operation-level social capabilities and keeps them offline', function () {
    $capabilities = ResearchBackedSocialCapabilities::candidates();
    expect($capabilities)->not->toBeEmpty()
        ->and(array_filter($capabilities, fn ($c) => $c->support === CapabilitySupport::Supported))->not->toBeEmpty();

    foreach ($capabilities as $capability) {
        expect($capability->liveEnabled)->toBeFalse()
            ->and($capability->readiness->value)->toBe('sandbox_only')
            ->and($capability->sourceUrl)->toStartWith('https://')
            ->and($capability->isUsableOffline($capability->requiredScopes, $capability->requiredRoles, new DateTimeImmutable('2026-10-10T00:00:00+00:00')))->toBeTrue();
    }
});

it('does not confuse unsupported platform operations with a generic publish contract', function () {
    $caps = ResearchBackedSocialCapabilities::candidates();
    expect(array_filter($caps, fn ($c) => $c->platform === SocialPlatform::TikTok && $c->operation === SocialOperation::Edit))->toBe([])
        ->and(array_filter($caps, fn ($c) => $c->platform === SocialPlatform::YouTube && $c->operation === SocialOperation::Schedule))->toBe([])
        ->and(array_filter($caps, fn ($c) => $c->platform === SocialPlatform::LinkedIn && $c->operation === SocialOperation::Analytics))->toBe([]);
});

it('fails closed on stale, missing scope or role evidence', function () {
    $capability = ResearchBackedSocialCapabilities::candidates()[0];
    expect($capability->isUsableOffline([], [], new DateTimeImmutable('2026-10-10T00:00:00+00:00')))->toBeFalse()
        ->and($capability->isUsableOffline($capability->requiredScopes, $capability->requiredRoles, new DateTimeImmutable('2026-12-01T00:00:00+00:00')))->toBeFalse();
});

it('rejects live-enabled social candidates', function () {
    expect(fn () => new SocialCapability(
        SocialPlatform::LinkedIn, SocialOperation::Create, 'x', CapabilitySupport::Supported,
        ProviderReadinessStatus::Ready, [], [], [], 'https://example.test',
        new DateTimeImmutable('2026-10-04T00:00:00+00:00'), null, true,
    ))->toThrow(InvalidArgumentException::class, 'live provider publication');
});
